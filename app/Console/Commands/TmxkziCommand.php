<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;

class TmxkziCommand extends Command
{
    protected $signature = 'tmxkzi';
    protected $description = 'Show available TMXKZI commands';

    public function handle()
    {
        $this->newLine();
        $this->info('╔═════════════════════════════════════════╗');
        $this->info('║           TMXKZI Commands              ║');
        $this->info('╚═════════════════════════════════════════╝');
        $this->newLine();

        $this->info('Available commands:');
        $this->newLine();

        $this->info('   Network Statistics Manager:');
        $this->line('   • tmxkzi:network:install');
        $this->line('     Install Network Statistics Manager addon');
        $this->line('   • tmxkzi:network:uninstall');
        $this->line('     Remove Network Statistics Manager addon');
        
        $this->newLine();
        $this->line('   Run any command with --help to see its usage information.');
        $this->newLine();

        return 0;
    }
}
