<?php

namespace Pterodactyl\Console\Commands\Server;

use Illuminate\Console\Command;
use Pterodactyl\Jobs\Server\BatchStatisticsCollectionJob;

class DispatchBatchStatisticsCommand extends Command
{
    protected $signature = 'p:server:dispatch-batch
                            {--batch-size=5 : Number of servers to process in each batch}';
    
    protected $description = 'Dispatch a BatchStatisticsCollectionJob to the queue for testing';
    
    public function handle()
    {
        $batchSize = (int) $this->option('batch-size');
        
        $this->info('Dispatching BatchStatisticsCollectionJob with batch size: ' . $batchSize);
        
        // Generate a unique partition ID for this job
        $partitionId = 'test-' . time();
        
        BatchStatisticsCollectionJob::dispatch($batchSize, $partitionId);
        
        $this->info('Job dispatched with partition ID: ' . $partitionId);
        $this->info('Run "php artisan queue:work" to process this job');
        
        return 0;
    }
}
