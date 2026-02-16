<?php

namespace Pterodactyl\Services\PanicMode;

use Pterodactyl\Models\PanicModeSetting;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\PanicMode\DiscordNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BandwidthMonitor
{
    /**
     * @var DiscordNotifier
     */
    protected $discordNotifier;

    /**
     * BandwidthMonitor constructor.
     *
     * @param DiscordNotifier $discordNotifier
     */
    public function __construct(DiscordNotifier $discordNotifier)
    {
        $this->discordNotifier = $discordNotifier;
    }

    /**
     * Check all servers for bandwidth usage exceeding the threshold.
     *
     * @return array
     */
    public function checkAllServers(): array
    {
        if (!PanicModeSetting::isPanicModeEnabled()) {
            return ['success' => true, 'message' => 'Panic Mode is disabled.', 'alerts_sent' => 0];
        }

        $threshold = PanicModeSetting::getBandwidthThreshold();
        $alertsSent = 0;
        $serversExceedingThreshold = [];

        try {
            $latestStats = $this->getLatestServerStatistics();
            
            foreach ($latestStats as $stat) {
                if (!$stat->prev_collected_at) {
                    continue;
                }
                $timeDiff = strtotime($stat->collected_at) - strtotime($stat->prev_collected_at);
                if ($timeDiff <= 0) {
                    continue;
                }
                $bytesDiff = ($stat->rx_bytes - $stat->prev_rx_bytes) + ($stat->tx_bytes - $stat->prev_tx_bytes);
                $bitsDiff = $bytesDiff * 8;
                $mbps = $bitsDiff / $timeDiff / 1000000;
                if ($mbps > $threshold) {
                    $server = Server::where('uuid', $stat->server_uuid)->first();
                    if (!$server) {
                        continue;
                    }
                    
                    $serversExceedingThreshold[] = [
                        'server_id' => $server->id,
                        'server_name' => $server->name,
                        'bandwidth_mbps' => $mbps,
                        'threshold_mbps' => $threshold
                    ];
                    $alertSent = $this->discordNotifier->sendBandwidthAlert($server, $mbps);
                    if ($alertSent) {
                        $alertsSent++;
                    }
                }
            }
            
            return [
                'success' => true,
                'message' => 'Bandwidth check completed.',
                'alerts_sent' => $alertsSent,
                'servers_exceeding_threshold' => $serversExceedingThreshold
            ];
        } catch (\Exception $e) {
            Log::error('Error in Panic Mode bandwidth check: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error checking bandwidth: ' . $e->getMessage(),
                'alerts_sent' => 0
            ];
        }
    }

    /**
     * Get the latest statistics for each server with previous data point for rate calculation.
     *
     * @return \Illuminate\Support\Collection
     */
    private function getLatestServerStatistics()
    {
        $latestIds = DB::table('statistics_days')
            ->select('statistics_days.server_uuid', DB::raw('MAX(statistics_days.id) as id'))
            ->whereNotNull('statistics_days.server_uuid')
            ->join('servers', 'statistics_days.server_uuid', '=', 'servers.uuid')
            ->groupBy('statistics_days.server_uuid')
            ->pluck('id');
        $prevStatsQuery = DB::table(function($query) use ($latestIds) {
            $query->from('statistics_days')
                  ->select([
                      'server_uuid',
                      'id',
                      'rx_bytes',
                      'tx_bytes',
                      'collected_at',
                      DB::raw('ROW_NUMBER() OVER (PARTITION BY server_uuid ORDER BY id DESC) as rn')
                  ])
                  ->whereIn('server_uuid', function($q) use ($latestIds) {
                      $q->from('statistics_days')
                        ->whereIn('id', $latestIds)
                        ->select('server_uuid');
                  });
        }, 'ranked_stats')
        ->where('rn', '=', 2)
        ->select([
            'server_uuid',
            'id',
            'rx_bytes as prev_rx_bytes',
            'tx_bytes as prev_tx_bytes',
            'collected_at as prev_collected_at'
        ]);
        return DB::table('statistics_days AS current')
            ->select([
                'current.id',
                'current.server_uuid',
                'current.rx_bytes',
                'current.tx_bytes',
                'current.collected_at',
                'servers.name as server_name',
                'servers.id as server_id',
                'prev.rx_bytes as prev_rx_bytes',
                'prev.tx_bytes as prev_tx_bytes',
                'prev.collected_at as prev_collected_at'
            ])
            ->join('servers', function($join) {
                $join->on('current.server_uuid', '=', 'servers.uuid');
            })
            ->leftJoinSub($prevStatsQuery, 'prev_stats', function($join) {
                $join->on('current.server_uuid', '=', 'prev_stats.server_uuid');
            })
            ->leftJoin('statistics_days as prev', 'prev.id', '=', 'prev_stats.id')
            ->whereIn('current.id', $latestIds)
            ->get();
    }
}
