<?php

namespace Pterodactyl\Services\Statistics;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\NetworkStatisticSetting;
use Pterodactyl\Jobs\Server\BatchStatisticsCollectionJob;

class StatisticsKernelHelpers
{
    /**
     * Configure statistics collection scheduler based on configured interval.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     */
    public function scheduleStatisticsCollection(Schedule $schedule): void
    {
        try {
            $event = $schedule->call(function() {
                try {
                    $this->dispatchStatisticsJob();
                } catch (\Exception $e) {
                    Log::error("Statistics dispatch error: " . $e->getMessage());
                }
            });
            $event->everyMinute();
            $schedule->command('p:server:monitor-stats-jobs')->everyFiveMinutes();
        } catch (\Exception $e) {
            $schedule->command('p:server:collect')->hourly();
        }
    }

    /**
     * Dispatch a statistics collection job with proper partitioning.
     */
    private function dispatchStatisticsJob(): void
    {
        $totalServers = Server::count();
        
        if ($totalServers === 0) {
            return; 
        }

        $batchSize = $this->calculateOptimalBatchSize($totalServers);
        BatchStatisticsCollectionJob::dispatch($batchSize);
    }

    /**
     * Calculate optimal batch size based on server count.
     */
    private function calculateOptimalBatchSize(int $totalServers): int
    {
        if ($totalServers < 50) {
            return max(5, $totalServers);
        } elseif ($totalServers < 200) {
            return 20; 
        } elseif ($totalServers < 500) {
            return 50;
        } else {
            return 100; 
        }
    }

    // Stale job checking has been removed - rely on Laravel's queue system
    // to handle job timeouts and retries
}
