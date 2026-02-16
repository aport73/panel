<?php

namespace Pterodactyl\Console\Commands\Server;

use Illuminate\Console\Command;

class MonitorStatisticsJobsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'p:server:monitor-stats-jobs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Legacy command - statistics monitoring is now handled by the queue system';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Statistics job monitoring is now handled by the Laravel queue system.');
        $this->info('This command is kept for backward compatibility but performs no actions.');
        $this->info('Use "php artisan queue:work" to process queued jobs.');
        
        return 0;
    }
}
