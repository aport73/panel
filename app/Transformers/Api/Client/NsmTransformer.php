<?php

namespace Pterodactyl\Transformers\Api\Client;

use Illuminate\Support\Arr;

class NsmTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return 'stats';
    }

    /**
     * Transform stats from the daemon into a result set that can be used in
     * the client API.
     */
    public function transform(array $data): array
    {
    $networkStats = Arr::get($data, 'utilization.network', []);
        $resources = [
        'network_rx_bytes' => (int) max(0, min(Arr::get($networkStats, 'rx_bytes', 0), PHP_INT_MAX)),
        'network_tx_bytes' => (int) max(0, min(Arr::get($networkStats, 'tx_bytes', 0), PHP_INT_MAX)),
        'network_rx_packets' => (int) max(0, min(Arr::get($networkStats, 'rx_packets', 0), PHP_INT_MAX)),
        'network_tx_packets' => (int) max(0, min(Arr::get($networkStats, 'tx_packets', 0), PHP_INT_MAX)),
    ];

    $hasNetworkStats = $resources['network_rx_bytes'] > 0 || 
                      $resources['network_tx_bytes'] > 0 || 
                      $resources['network_rx_packets'] > 0 || 
                      $resources['network_tx_packets'] > 0;

    if (Arr::get($data, 'state') === 'running' && isset($data['server_uuid']) && $hasNetworkStats) {
        try {
            $server = Server::where('uuid', $data['server_uuid'])->first();
            if (!$server) {
                return $this->transformResponse($data, $resources);
            }

            if ($server->isSuspended()) {
                return $this->transformResponse($data, $resources);
            }

            DB::transaction(function () use ($server, $resources) {
                $stat = new StatisticsDay([
                    'server_uuid' => $server->uuid,
                    'rx_bytes' => $resources['network_rx_bytes'],
                    'tx_bytes' => $resources['network_tx_bytes'],
                    'rx_packets' => $resources['network_rx_packets'],
                    'tx_packets' => $resources['network_tx_packets'],
                    'collected_at' => Carbon::now(),
                ]);

                if (!$stat->save()) {
                    throw new \RuntimeException('Failed to save statistics record');
                }

                Cache::tags(['server.stats'])->forget("server.stats.{$server->uuid}.*");
            });

        } catch (\Exception $e) {
            Log::error('Failed to store statistics', [
                'server_uuid' => $server->uuid ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }

    return $this->transformResponse($data, $resources);
    }
    /**
     * Get historical statistics for a server.
     * @throws AuthorizationException
     */
    public function getHistoricalStats(Server $server): array
    {
        if (!auth()->user()->can('view-stats', $server)) {
            throw new AuthorizationException('Not authorized to view server statistics');
        }

        try {
            return Cache::remember("server.stats.{$server->uuid}", 60, function () use ($server) {
                $stats = StatisticsDay::where('server_uuid', $server->uuid)
                    ->where('collected_at', '>=', Carbon::now()->subDay())
                    ->orderBy('collected_at')
                    ->get()
                    ->map(function ($stat) {
                        return [
                            'timestamp' => (int) $stat->collected_at->timestamp,
                            'rx_bytes' => (int) max(0, $stat->rx_bytes),
                            'tx_bytes' => (int) max(0, $stat->tx_bytes),
                            'rx_packets' => (int) max(0, $stat->rx_packets),
                            'tx_packets' => (int) max(0, $stat->tx_packets),
                        ];
                    });

                return [
                    'data' => $stats->values(),
                    'meta' => [
                        'total' => $stats->count(),
                        'days' => 1,
                    ],
                ];
            });
        } catch (\Exception $e) {
            Log::error('Failed to fetch historical statistics', [
                'server_uuid' => $server->uuid,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Transform the response data.
     */
    private function transformResponse(array $data, array $resources): array
    {
        return [
            'current_state' => Arr::get($data, 'state', 'stopped'),
            'is_suspended' => (bool) Arr::get($data, 'is_suspended', false),
            'resources' => $resources,
        ];
    }
}
