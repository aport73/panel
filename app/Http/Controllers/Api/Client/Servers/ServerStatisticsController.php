<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Cache\RateLimiter;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\StatisticsDay;
use Pterodactyl\Models\NetworkStatisticSetting;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Statistics\GetStatisticsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Statistics\StoreStatisticsRequest;
use Pterodactyl\Transformers\Api\Client\NsmTransformer;

class ServerStatisticsController extends ClientApiController
{
    private const CHUNK_SIZE = 250;

    public function __construct(protected RateLimiter $limiter) 
    {
        parent::__construct();
    }

    /**
     * Get historical network statistics for a server.
     */
    public function index(Request $request, Server $server): JsonResponse
    {
        if ($server->isSuspended()) {
            return response()->json(['error' => 'This server is currently suspended.'], 403);
        }

        try {
            $intervalMinutes = $request->cookie('nsm_interval_minutes', 15);
            if (!in_array($intervalMinutes, [15, 30, 45, 60])) {
                $intervalMinutes = 15;
            }
            $cacheKey = "server.stats.{$server->uuid}.{$intervalMinutes}";
            try {
                $cacheTtl = NetworkStatisticSetting::getCollectionInterval();
            } catch (\Exception $e) {
                $cacheTtl = 60;
            }
            
            return response()->json(
                Cache::remember($cacheKey, $cacheTtl, function () use ($server, $intervalMinutes) {
                    try {
                        $retentionHours = NetworkStatisticSetting::getRetentionHours();
                        $collectionInterval = NetworkStatisticSetting::getCollectionInterval();
                    } catch (\Exception $e) {
                        $retentionHours = 24; 
                        $collectionInterval = 60; 
                    }
                    
                    $stats = StatisticsDay::query()
                        ->select([
                            'collected_at',
                            'rx_bytes',
                            'tx_bytes',
                            'rx_packets',
                            'tx_packets',
                            DB::raw('UNIX_TIMESTAMP(collected_at) as timestamp')
                        ])
                        ->where('server_uuid', $server->uuid)
                        ->where('collected_at', '>=', Carbon::now()->subDay())
                        ->orderBy('collected_at', 'asc')
                        ->get();
                    
                    $result = $this->calculateRateStats($stats, $intervalMinutes);
                    $result['settings'] = [
                        'retention_hours' => $retentionHours,
                        'collection_interval' => $collectionInterval,
                        'retention_text' => self::formatRetentionPeriod($retentionHours),
                        'collection_text' => self::formatCollectionInterval($collectionInterval),
                        'interval_minutes' => $intervalMinutes,
                        'available_intervals' => [15, 30, 45, 60]
                    ];
                    
                    return $result;
                })
            );
        } catch (\Exception $e) {
            Log::error('Failed to fetch statistics', [
                'error' => $e->getMessage(),
                'server' => $server->uuid
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Store network statistics for a server.
     */
    public function store(StoreStatisticsRequest $request, Server $server): JsonResponse
    {
        if ($server->isSuspended()) {
            return response()->json(['error' => 'Cannot store statistics for a suspended server.'], 403);
        }

        try {
            DB::transaction(function () use ($server, $request) {
                $data = $request->validated();
                
                $latestStats = StatisticsDay::query()
                    ->where('server_uuid', $server->uuid)
                    ->orderBy('collected_at', 'desc')
                    ->first(['rx_packets', 'tx_packets']);

                StatisticsDay::create([
                    'server_uuid' => $server->uuid,
                    'rx_bytes' => $data['network_rx_bytes'],
                    'tx_bytes' => $data['network_tx_bytes'],
                    'rx_packets' => $this->calculatePacketDiff($data['network_rx_packets'], $latestStats?->rx_packets),
                    'tx_packets' => $this->calculatePacketDiff($data['network_tx_packets'], $latestStats?->tx_packets),
                    'collected_at' => Carbon::now(),
                ]);

                Cache::tags(['server.stats'])->forget("server.stats.{$server->uuid}.*");
            });

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to store statistics', [
                'error' => $e->getMessage(),
                'server' => $server->uuid,
            ]);
            
            return response()->json(['error' => 'Failed to store statistics'], 500);
        }
    }

    /**
     * Clean up old statistics to prevent database bloat.
     * This method has been optimized to handle large numbers of servers
     * by processing data in smaller batches and avoiding a single large transaction.
     */
    public function cleanup(): void
    {
        try {
            $this->cleanupOldStatsByDate();
            Server::query()
                ->orderBy('id')
                ->chunk(50, function ($servers) {
                    foreach ($servers as $server) {
                        try {
                            DB::transaction(function () use ($server) {
                                $this->cleanupServerStats($server);
                            }, 3);
                        } catch (\Exception $e) {
                            Log::error('Failed to cleanup server statistics', [
                                'server' => $server->uuid,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                });
            $this->resetAutoIncrementIfNeeded();
            
        } catch (\Exception $e) {
            Log::error('Failed to cleanup statistics', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clean up old statistics by date in smaller chunks.
     */
    private function cleanupOldStatsByDate(): void
    {
        try {
            $retentionHours = NetworkStatisticSetting::getRetentionHours();
            if (!$retentionHours) {
                $retentionHours = 24;
            }
            
            $cutoffDate = Carbon::now()->subHours($retentionHours);
            
            StatisticsDay::query()
                ->where('collected_at', '<', $cutoffDate)
                ->orderBy('id')
                ->chunkById(self::CHUNK_SIZE, function ($records) {
                    DB::transaction(function () use ($records) {
                        foreach ($records as $record) {
                            $record->forceDelete();
                        }
                    }, 3);
                });
        } catch (\Exception $e) {
            $cutoffDate = Carbon::now()->subDay();
            
            Log::error('Error getting retention period, falling back to default', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            StatisticsDay::query()
                ->where('collected_at', '<', $cutoffDate)
                ->orderBy('id')
                ->chunkById(self::CHUNK_SIZE, function ($records) {
                    DB::transaction(function () use ($records) {
                        foreach ($records as $record) {
                            $record->forceDelete();
                        }
                    }, 3);
                });
        }
    }

    /**
     * Clean up statistics for a specific server.
     * Optimized to handle servers with large amounts of data.
     */
    private function cleanupServerStats(Server $server): void
    {
        try {
            $retentionHours = NetworkStatisticSetting::getRetentionHours();
            if (!$retentionHours) {
                $retentionHours = 24;
            }
            
            $cutoffDate = Carbon::now()->subHours($retentionHours);
            StatisticsDay::where('server_uuid', $server->uuid)
                ->where('collected_at', '<', $cutoffDate)
                ->limit(1000)
                ->forceDelete();
        } catch (\Exception $e) {
            $cutoffDate = Carbon::now()->subDay();
            
            StatisticsDay::where('server_uuid', $server->uuid)
                ->where('collected_at', '<', $cutoffDate)
                ->limit(1000) 
                ->forceDelete();
        }
        Cache::forget(sprintf('server.stats.%s', $server->uuid));
    }

    private static $rxRates = [];
    private static $txRates = [];
    private static $windowSize = 5; 

    /**
     * Calculate rolling average of rates
     */
    private function calculateRollingAverage(array &$rateWindow, float $newRate): float
    {
        $rateWindow[] = $newRate;
        if (count($rateWindow) > self::$windowSize) {
            array_shift($rateWindow);
        }
        return array_sum($rateWindow) / count($rateWindow);
    }

    /**
     * Calculate rate statistics from raw data.
     */
    private function calculateRateStats($stats): array
    {
        $rateStats = collect();
        $previous = null;

        foreach ($stats as $stat) {
            if ($previous) {
                $timeDiff = $stat->timestamp - $previous->timestamp;
                if ($timeDiff > 0) {
                    $rxDiff = max(0, (int) ($stat->rx_bytes - $previous->rx_bytes));
                    $txDiff = max(0, (int) ($stat->tx_bytes - $previous->tx_bytes));
                    
                    $rxRate = $rxDiff / $timeDiff;
                    $txRate = $txDiff / $timeDiff;

                    $smoothedRxRate = $this->calculateRollingAverage(self::$rxRates, $rxRate);
                    $smoothedTxRate = $this->calculateRollingAverage(self::$txRates, $txRate);

                    $rateStats->push([
                        'timestamp' => (int) $stat->timestamp,
                        'rx_bytes' => (int) ($smoothedRxRate * $timeDiff),
                        'tx_bytes' => (int) ($smoothedTxRate * $timeDiff),
                        'rx_packets' => max(0, (int) ($stat->rx_packets - $previous->rx_packets)),
                        'tx_packets' => max(0, (int) ($stat->tx_packets - $previous->tx_packets))
                    ]);
                }
            }
            $previous = $stat;
        }

        return [
            'data' => $rateStats->values(),
            'meta' => [
                'total' => $rateStats->count(),
                'days' => 1
            ],
        ];
    }

    /**
     * Format retention period into human readable text
     */
    private static function formatRetentionPeriod(int $hours): string
    {
        if ($hours < 24) {
            return $hours . ' ' . ($hours == 1 ? 'hour' : 'hours');
        }
        
        $days = floor($hours / 24);
        return $days . ' ' . ($days == 1 ? 'day' : 'days');
    }
    
    /**
     * Format collection interval into human readable text
     */
    private static function formatCollectionInterval(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' ' . ($seconds == 1 ? 'second' : 'seconds');
        }
        
        $minutes = floor($seconds / 60);
        return $minutes . ' ' . ($minutes == 1 ? 'minute' : 'minutes');
    }
    
    /**
     * Calculate packet difference with safety checks.
     */
    private function calculatePacketDiff(int $current, ?int $previous): int
    {
        if ($previous === null) {
            return $current;
        }
        return max(0, $current - $previous);
    }

    /**
     * Reset the auto-increment counter for the statistics_days table if needed.
     * This prevents the ID from growing too large over time.
     * Uses Laravel's Schema builder and query builder to avoid raw SQL.
     */
    private function resetAutoIncrementIfNeeded(): void
    {
        try {
            $tableName = (new StatisticsDay())->getTable();
            $totalRows = DB::table($tableName)->count();
            $maxId = DB::table($tableName)->max('id') ?? 0;
            $nextId = $maxId + 1;
            if ($nextId > 100000 && $totalRows < 50000) {
                $newAutoIncrement = $maxId > 0 ? $maxId + 1 : 1;
                $connection = DB::connection();
                $prefix = $connection->getTablePrefix();
                $table = $prefix . $tableName;
                if ($tableName !== 'statistics_days') {
                    Log::error('Attempted to reset auto-increment on invalid table', [
                        'attempted_table' => $tableName
                    ]);
                    return; 
                }
                if ($newAutoIncrement < 1 || $newAutoIncrement > 1000000000) {
                    Log::error('Invalid auto-increment value', [
                        'attempted_value' => $newAutoIncrement
                    ]);
                    return;
                }
                DB::statement('ALTER TABLE `' . $tableName . '` AUTO_INCREMENT = ' . (int)$newAutoIncrement);
                try {
                    $verifyStatus = DB::select('SHOW TABLE STATUS LIKE \'statistics_days\'');
                    if (!empty($verifyStatus) && isset($verifyStatus[0]->Auto_increment)) {
                        $newValue = $verifyStatus[0]->Auto_increment;
                        if ($newValue != $newAutoIncrement) {
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to verify auto-increment reset', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to reset auto-increment counter', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Get the server's network statistics.
     */
    public function resources(Request $request, Server $server)
    {
        $stats = StatisticsDay::where('server_uuid', $server->uuid)
            ->orderBy('collected_at', 'desc')
            ->first();

        if (!$stats) {
            return $this->fractal->item([
                'state' => 'stopped',
                'is_suspended' => $server->isSuspended(),
                'utilization' => [
                    'network' => [
                        'rx_bytes' => 0,
                        'tx_bytes' => 0,
                        'rx_packets' => 0,
                        'tx_packets' => 0
                    ]
                ]
            ], $this->getTransformer(NsmTransformer::class));
        }

        return $this->fractal->item([
            'state' => 'running',
            'is_suspended' => $server->isSuspended(),
            'utilization' => [
                'network' => [
                    'rx_bytes' => $stats->rx_bytes,
                    'tx_bytes' => $stats->tx_bytes,
                    'rx_packets' => $stats->rx_packets,
                    'tx_packets' => $stats->tx_packets
                ]
            ]
        ], $this->getTransformer(NsmTransformer::class));
    }
}
