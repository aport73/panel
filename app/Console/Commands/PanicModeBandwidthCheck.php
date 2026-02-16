<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Models\PanicModeSetting;
use Pterodactyl\Services\PanicMode\BandwidthMonitor;

class PanicModeBandwidthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pterodactyl:panic-mode:check-bandwidth';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check server bandwidth usage and trigger Panic Mode alerts if thresholds are exceeded';

    /**
     * @var BandwidthMonitor
     */
    protected $bandwidthMonitor;

    /**
     * Create a new command instance.
     *
     * @param BandwidthMonitor $bandwidthMonitor
     */
    public function __construct(BandwidthMonitor $bandwidthMonitor)
    {
        parent::__construct();
        $this->bandwidthMonitor = $bandwidthMonitor;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!PanicModeSetting::isPanicModeEnabled()) {
            $this->info('Panic Mode is disabled. No bandwidth checks will be performed.');
            return;
        }

        $this->info('Starting Panic Mode bandwidth check...');
        
        $threshold = PanicModeSetting::getBandwidthThreshold();
        $this->info("Current bandwidth threshold: {$threshold} Mbps");
        
        $result = $this->bandwidthMonitor->checkAllServers();
        
        if (!$result['success']) {
            $this->error($result['message']);
            return;
        }
        
        $this->info("Bandwidth check completed.");
        $this->info("Servers exceeding threshold: " . count($result['servers_exceeding_threshold']));
        $this->info("Alerts sent: {$result['alerts_sent']}");
        
        if (count($result['servers_exceeding_threshold']) > 0) {
            $this->table(
                ['Server ID', 'Server Name', 'Bandwidth (Mbps)', 'Threshold (Mbps)'],
                array_map(function ($server) {
                    return [
                        $server['server_id'],
                        $server['server_name'],
                        number_format($server['bandwidth_mbps'], 2),
                        $server['threshold_mbps']
                    ];
                }, $result['servers_exceeding_threshold'])
            );
        }
    }
}
