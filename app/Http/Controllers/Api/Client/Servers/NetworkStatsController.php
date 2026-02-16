<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Cache\RateLimiter;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerStatistic;
use Pterodactyl\Models\NetworkStatisticSetting;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Statistics\GetNetworkStatsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Statistics\GetProtocolStatsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Statistics\StoreNetworkStatsRequest;
use Pterodactyl\Transformers\Api\Client\NsmTransformer;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

class NetworkStatsController extends ClientApiController
{
    protected const MAX_DAYS = 30;
    protected const MIN_DAYS = 1;
    protected const RATE_LIMIT_PER_MINUTE = 60;
    protected const DEFAULT_CACHE_TTL = 60;

    public function __construct(protected RateLimiter $limiter) 
    {
        parent::__construct();
    }

    /**
     * Get current network statistics for a server.
     */
    public function index(GetNetworkStatsRequest $request, Server $server): JsonResponse
    {
        $this->checkRateLimit($server);

        try {
            $cacheKey = "network_stats:{$server->uuid}";
            try {
                $cacheTtl = NetworkStatisticSetting::getCollectionInterval() ?: self::DEFAULT_CACHE_TTL;
            } catch (\Exception $e) {
                $cacheTtl = self::DEFAULT_CACHE_TTL;
            }
            
            return response()->json(
                Cache::remember($cacheKey, $cacheTtl, function () use ($server) {
                    $transformer = new NsmTransformer();
                    $stats = $transformer->transform([
                        'state' => $server->status,
                        'is_suspended' => $server->isSuspended(),
                        'utilization' => [
                            'network' => [
                                'rx_bytes' => $server->rx_bytes ?? 0,
                                'tx_bytes' => $server->tx_bytes ?? 0,
                                'rx_packets' => $server->rx_packets ?? 0,
                                'tx_packets' => $server->tx_packets ?? 0,
                            ],
                        ],
                    ]);

                    return $stats['resources']['network'];
                })
            );
        } catch (\Exception $e) {
            Log::error('Failed to fetch network stats', [
                'error' => $e->getMessage(),
                'server' => $server->uuid
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Store network statistics for a server.
     */
    public function store(StoreNetworkStatsRequest $request, Server $server): JsonResponse
    {
        $this->checkRateLimit($server);

        try {
            $data = $request->validated();

            \DB::transaction(function () use ($server, $data) {
                ServerStatistic::create([
                    'server_id' => $server->id,
                    'rx_bytes' => $data['rx_bytes'],
                    'tx_bytes' => $data['tx_bytes'],
                    'rx_packets' => $data['rx_packets'],
                    'tx_packets' => $data['tx_packets'],
                    'collected_at' => Carbon::now(),
                ]);

                Cache::tags(['network_stats'])->forget("network_stats:{$server->uuid}");
            });

            return response()->json([], 204);
        } catch (\Exception $e) {
            Log::error('Failed to store network stats', [
                'error' => $e->getMessage(),
                'server' => $server->uuid
            ]);
            return response()->json(['error' => 'Failed to store statistics'], 500);
        }
    }

    /**
     * Get historical network statistics for a server.
     */
    public function history(GetNetworkStatsRequest $request, Server $server): JsonResponse
    {
        $this->checkRateLimit($server);

        try {
            $days = min(self::MAX_DAYS, max(self::MIN_DAYS, (int) $request->input('days', 30)));
            $cacheKey = "network_stats_history:{$server->uuid}:{$days}";
            try {
                $cacheTtl = NetworkStatisticSetting::getCollectionInterval() ?: self::DEFAULT_CACHE_TTL;
            } catch (\Exception $e) {
                $cacheTtl = self::DEFAULT_CACHE_TTL;
            }

            return response()->json(
                Cache::remember($cacheKey, $cacheTtl, function () use ($server, $days) {
                    $stats = ServerStatistic::query()
                        ->select(['collected_at', 'rx_bytes', 'tx_bytes', 'rx_packets', 'tx_packets'])
                        ->where('server_id', $server->id)
                        ->where('collected_at', '>=', Carbon::now()->subDays($days))
                        ->orderBy('collected_at')
                        ->chunk(100, function ($chunk) use (&$result) {
                            foreach ($chunk as $stat) {
                                $result[] = [
                                    'timestamp' => $stat->collected_at->timestamp,
                                    'rx_bytes' => (int) $stat->rx_bytes,
                                    'tx_bytes' => (int) $stat->tx_bytes,
                                    'rx_packets' => (int) $stat->rx_packets,
                                    'tx_packets' => (int) $stat->tx_packets,
                                ];
                            }
                        });

                    return [
                        'data' => $result ?? [],
                        'timespan' => $days,
                    ];
                })
            );
        } catch (\Exception $e) {
            Log::error('Failed to fetch network stats history', [
                'error' => $e->getMessage(),
                'server' => $server->uuid
            ]);
            return response()->json(['error' => 'Failed to fetch statistics'], 500);
        }
    }

    /**
     * Check rate limiting for the current request.
     */
    protected function checkRateLimit(Server $server): void
    {
        $key = "network_stats_rate_limit:{$server->uuid}";
        
        if ($this->limiter->tooManyAttempts($key, self::RATE_LIMIT_PER_MINUTE)) {
            abort(429, 'Too many requests. Please try again later.');
        }

        $this->limiter->hit($key, 60);
    }

    /**
     * Get protocol-specific network statistics from Wings.
     */
    public function protocols(GetProtocolStatsRequest $request, Server $server): JsonResponse
    {
        $this->checkRateLimit($server);

        try {
            $cacheKey = "protocol_stats:{$server->uuid}";
            try {
                $cacheTtl = NetworkStatisticSetting::getCollectionInterval() ?: self::DEFAULT_CACHE_TTL;
            } catch (\Exception $e) {
                $cacheTtl = self::DEFAULT_CACHE_TTL;
            }
            
            return response()->json(
                Cache::remember($cacheKey, $cacheTtl, function () use ($server) {
                    $stats = app(DaemonServerRepository::class)
                        ->setServer($server)
                        ->getProtocolStats();

                    return [
                        'data' => $stats['data'],
                        'timestamp' => Carbon::now()->timestamp
                    ];
                })
            );
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch protocol statistics'], 500);
        }
    }
}
