<?php

namespace Pterodactyl\Services\Statistics;

use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Jobs\Server\BatchStatisticsCollectionJob;

class StatisticsCollectionService
{
    /**
     * Dispatch a statistics collection job.
     */
    public function dispatchIfNeeded(): void
    {
        try {
            $this->dispatchStatisticsJob();
        } catch (\Exception $e) {
            Log::error("Statistics dispatch error: " . $e->getMessage());
        }
    }
    
    /**
     * Dispatch a statistics collection job.
     */
    public function dispatchStatisticsJob(): void
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
