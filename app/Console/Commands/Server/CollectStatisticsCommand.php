<?php

namespace Pterodactyl\Console\Commands\Server;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerTransfer;
use Pterodactyl\Models\AnalyticsData;
use Pterodactyl\Jobs\Server\BatchStatisticsCollectionJob;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Pterodactyl\Http\Controllers\Api\Client\Servers\ServerStatisticsController;

class CollectStatisticsCommand extends Command
{
    protected $signature = 'p:server:collect 
                            {--batch-size=50 : Number of servers to process in each batch} 
                            {--delay=1 : Delay in seconds between batches}
                            {--max-runtime=110 : Maximum runtime in seconds before stopping}
                            {--last-server-id=0 : Last processed server ID (for continuation)}
                            {--cleanup-only : Only run cleanup without collecting statistics}
                            {--no-cleanup : Skip cleanup and only collect statistics}
                            {--force : Force the command to run even when maintenance mode is enabled.}';
    protected $description = 'Collect statistics about servers. Use this command to gather server statistics.';

    /**
     * Flag to indicate this is running within a job context.
     *
     * @var bool
     */
    protected $runningWithinJob = false;
    
    /**
     * The cache key used to prevent overlapping job dispatching.
     *
     * @var string
     */
    protected $dispatchLockKey = 'statistics:dispatch:lock';
    
    /**
     * The number of seconds to wait before allowing another dispatch.
     *
     * @var int
     */
    protected $dispatchLockTime = 300; // 5 minutes
    
    public function handle()
    {
        // Prevent overlapping dispatches
        if (cache()->has($this->dispatchLockKey)) {
            $this->warn('Statistics collection is already running. Please wait before running again.');
            return 0;
        }
        
        // Set dispatch lock
        cache()->put($this->dispatchLockKey, true, now()->addSeconds($this->dispatchLockTime));
        
        try {
            // Always run cleanup if not explicitly skipped
            if (!$this->option('no-cleanup')) {
                $this->info('Running cleanup of old statistics...');
                $controller = app()->make(ServerStatisticsController::class);
                $controller->cleanup();
            }

            if ($this->option('cleanup-only')) {
                $this->info('Cleanup completed. Exiting as --cleanup-only was specified.');
                return 0;
            }
        
            $batchSize = (int)$this->option('batch-size');
            $delayBetweenBatches = (int)$this->option('delay');
            $totalServers = Server::count();
            $totalBatches = ceil($totalServers / $batchSize);
            
            $this->info("Found {$totalServers} servers to process in {$totalBatches} batches");
            $this->info("Batch size: {$batchSize} servers, Delay between batches: {$delayBetweenBatches} seconds");
            
            if ($totalBatches === 0) {
                $this->info('No servers found to process.');
                return 0;
            }
            
            $dispatchedBatches = 0;
            
            for ($i = 0; $i < $totalBatches; $i++) {
                $offset = $i * $batchSize;
                $currentBatch = $i + 1;
                $startServer = $offset + 1;
                $endServer = min($offset + $batchSize, $totalServers);
                
                $this->info("\nDispatching batch {$currentBatch}/{$totalBatches} (Servers {$startServer}-{$endServer} of {$totalServers})");
                
                try {
                    $job = BatchStatisticsCollectionJob::dispatch($batchSize, $offset)
                        ->delay(now()->addSeconds($i * $delayBetweenBatches));
                    $dispatchedBatches++;
                    $this->info("✓ Batch {$currentBatch} queued successfully");
                } catch (\Exception $e) {
                    $this->error("✗ Failed to queue batch {$currentBatch}");
                    Log::error('Failed to dispatch batch job', [
                        'batch' => $currentBatch,
                        'offset' => $offset,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
                
                if ($i < $totalBatches - 1) {
                    $this->info("Waiting {$delayBetweenBatches} seconds before next batch...");
                }
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('An error occurred while dispatching batch jobs: ' . $e->getMessage());
            Log::error('Error in CollectStatisticsCommand', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        } finally {
            // Always clear the dispatch lock
            cache()->forget($this->dispatchLockKey);
        }
    }
    
    /**
     * Process a batch of servers.
     *
     * @param \Illuminate\Database\Eloquent\Collection $servers
     * @return void
     */
    private function processBatchOfServers($servers): void
    {
        foreach ($servers as $server) {
            try {
                $job = new \Pterodactyl\Jobs\Server\CollectServerStatistics($server);
                dispatch($job);
            } catch (\Exception $e) {
                Log::error('Failed to dispatch statistics job for server', [
                    'server_id' => $server->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Dispatch this command as a queued job.
     */
    private function dispatchAsJob(): void
    {
        $batchSize = (int) $this->option('batch-size');
        $totalServers = \Pterodactyl\Models\Server::count();
        $totalBatches = (int) ceil($totalServers / $batchSize);
        $partitionId = 'manual_' . substr(md5(uniqid('', true)), 0, 8);
        
        $job = new \Pterodactyl\Jobs\Server\BatchStatisticsCollectionJob($batchSize, $partitionId);
        dispatch($job);
        
        $this->info('=== Statistics Collection Job Dispatched ===');
        $this->info("Total Servers: {$totalServers}");
        $this->info("Batch Size: {$batchSize}");
        $this->info("Total Batches: {$totalBatches}");
        $this->info("Partition ID: {$partitionId}");
        $this->info('==========================================');
    }
    
    /**
     * Set a flag indicating this command is running within a job context.
     * Used to prevent infinite job dispatching loops.
     *
     * @return $this
     */
    public function setRunningWithinJob()
    {
        $this->runningWithinJob = true;
        return $this;
     }
}
