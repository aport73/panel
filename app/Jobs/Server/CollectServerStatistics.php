<?php

namespace Pterodactyl\Jobs\Server;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

use Carbon\Carbon;
use Pterodactyl\Models\StatisticsDay;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\NetworkStatisticSetting;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;

class CollectServerStatistics implements ShouldQueue
{
    use Dispatchable, Queueable;

    protected $server;


    public function __construct(Server $server)
    {
        $this->server = $server;
    }


    public function handle()
    {
        if (!$this->server) {
            Log::error('No server provided to CollectServerStatistics job.');
            return;
        }

        try {
            $repository = app()->make(DaemonServerRepository::class);
            $repository->setServer($this->server);
            $details = $repository->getDetails();

            if (empty($details) || !isset($details['utilization'])) {
                Log::error('No utilization data received from Wings API for server: ' . $this->server->uuid);
                return;
            }

            $networkStats = Arr::get($details['utilization'], 'network', []);
            

            try {
                $collectionInterval = NetworkStatisticSetting::getCollectionInterval();
                if (!$collectionInterval) {
                    $collectionInterval = 60;
                }
            } catch (\Exception $e) {
                $collectionInterval = 60;
            }
            

            $recentRecord = StatisticsDay::where('server_uuid', $this->server->uuid)
                ->where('collected_at', '>=', Carbon::now()->subSeconds($collectionInterval))
                ->orderBy('collected_at', 'desc')
                ->first();

            if (!$recentRecord) {
                StatisticsDay::create([
                    'server_uuid' => $this->server->uuid,
                    'rx_bytes' => Arr::get($networkStats, 'rx_bytes', 0),
                    'tx_bytes' => Arr::get($networkStats, 'tx_bytes', 0),
                    'rx_packets' => Arr::get($networkStats, 'rx_packets', 0),
                    'tx_packets' => Arr::get($networkStats, 'tx_packets', 0),
                    'collected_at' => Carbon::now(),
                ]);

                $this->cleanupOldStatistics();
            } else {
            
            }

        } catch (\Exception $e) {
            Log::error('Failed to collect statistics for server', [
                'server' => $this->server->uuid,
                'error' => $e->getMessage()
            ]);
        }
    }
    

    private function cleanupOldStatistics(): void
    {
        try {

            $retentionHours = NetworkStatisticSetting::getRetentionHours();
            

            if (!$retentionHours) {
                $retentionHours = 24;
            }
            

            $cutoffDate = Carbon::now()->subHours($retentionHours);
            

            $deleted = StatisticsDay::where('server_uuid', $this->server->uuid)
                ->where('collected_at', '<', $cutoffDate)
                ->limit(100)
                ->delete();
                
            if ($deleted > 0) {
            
            }
            

            if (rand(1, 20) === 1) {
                $deleted = StatisticsDay::where('collected_at', '<', $cutoffDate)
                    ->limit(500)
                    ->delete();
            }
        } catch (\Exception $e) {
            Log::error('Failed to clean up old statistics', [
                'server' => $this->server->uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}