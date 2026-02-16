<?php

namespace Pterodactyl\Jobs\Server;

use Illuminate\Bus\Queueable;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\StatisticsDay;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Pterodactyl\Repositories\Wings\DaemonServerRepository as WingsDaemonServerRepository;

class BatchStatisticsCollectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 2;
    
    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 600;
    
    /**
     * Indicate if the job should be marked as failed on timeout.
     *
     * @var bool
     */
    public $failOnTimeout = true;
    
    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 1;
    
    /**
     * Determine the time at which the job should timeout.
     * This is stricter than Laravel's default timeout to ensure we abandon truly stale jobs.
     *
     * @return \DateTime
     */
    public function retryUntil()
    {
        return now()->addSeconds(90); 
    }
    
    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array
     */
    public function backoff()
    {
        return [10, 30, 60];
    }
    
    /**
     * Number of servers to process in this batch.
     */
    protected $batchSize;
    
    /**
     * Offset for pagination.
     */
    protected $offset;
    
    /**
     * Create a new job instance.
     * 
     * @param int $batchSize Number of servers to process in this batch
     * @param int $offset Offset for pagination
     */
    public function __construct(int $batchSize = 20, int $offset = 0)
    {
        $this->batchSize = $batchSize;
        $this->offset = $offset;
    }
    
    /**
     * Execute the job.
     */
    /**
     * Check if a circuit breaker is active for this job.
     * 
     * @return bool
     */
    private function isCircuitBreakerActive(): bool
    {
        return false;
    }
    
    /**
     * Record a job failure in the circuit breaker.
     */
    private function recordFailure(): void
    {
        
    }
    
    public function handle()
    {
        try {
            $startTime = microtime(true);
            $servers = Server::query()
                ->orderBy('id')
                ->skip($this->offset)
                ->take($this->batchSize)
                ->get();
                
            if ($servers->isEmpty()) {
                return; 
            }
            
            $processed = 0;
            $errors = 0;
            foreach ($servers as $server) {
                try {
                    $repository = app(WingsDaemonServerRepository::class);
                    $repository->setServer($server);
                    $details = $repository->getDetails();
                    
                    if (empty($details) || !isset($details['utilization'])) {
                        Log::error('No utilization data received from Wings API for server: ' . $server->uuid);
                        $errors++;
                        continue;
                    }
                    
                    $networkStats = $details['utilization']['network'] ?? [];
                    StatisticsDay::create([
                        'server_uuid' => $server->uuid,
                        'rx_bytes' => $networkStats['rx_bytes'] ?? 0,
                        'tx_bytes' => $networkStats['tx_bytes'] ?? 0,
                        'rx_packets' => $networkStats['rx_packets'] ?? 0,
                        'tx_packets' => $networkStats['tx_packets'] ?? 0,
                        'collected_at' => now(),
                    ]);
                    
                    $processed++;
                    
                } catch (\Exception $e) {
                    Log::error('Failed to process server statistics', [
                        'server_id' => $server->id,
                        'server_uuid' => $server->uuid,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $errors++;
                }
            }
            
        } catch (\Exception $e) {
            $this->recordFailure();
            Log::error('Batch statistics collection job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries
            ]);
            
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }
    
    
}
