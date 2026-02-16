<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class NetworkUninstallCommand extends Command
{
    protected $signature = 'tmxkzi:network:uninstall';
    protected $description = 'Remove Network Statistics Manager addon';

    public function handle()
    {
        $this->newLine();
        $this->info('╔═════════════════════════════════════════╗');
        $this->info('║  Network Statistics Manager Uninstall  ║');
        $this->info('╚═════════════════════════════════════════╝');
        $this->newLine();
        $this->warn('⚠  ATTENTION - BETA SOFTWARE');
        $this->line('   This uninstaller is currently in BETA and might potentially');
        $this->line('   break your panel. It is recommended to:');
        $this->line('   • Make a complete backup of your panel before proceeding');
        $this->line('   • Test the uninstaller in a development environment first');
        $this->newLine();
        $this->line('   This uninstaller will remove Network Statistics Manager');
        $this->line('   components from your Pterodactyl installation.');
        $this->newLine();
        $this->warn('   ⚠ USE AT YOUR OWN RISK');
        $this->newLine();
        $this->info('Files to be modified:');
        $this->line('   • app/Repositories/Daemon/DaemonServerRepository.php');
        $this->line('   • app/Console/Kernel.php');
        $this->line('   • routes/api-client.php');
        $this->line('   • resources/scripts/routers/routes.ts');
        $this->newLine();
        
        $this->line('   To proceed with the uninstallation, please confirm below.');
        if (!$this->confirm('   Ready to proceed with the uninstallation?', false)) {
            $this->newLine();
            $this->info('   ✖ Uninstallation cancelled.');
            return 0;
        }

        $this->newLine();
        $this->info('   ⟳ Removing Network Statistics Manager...');
        $this->newLine();
        $daemonPath = base_path('app/Repositories/Wings/DaemonServerRepository.php');
        if (File::exists($daemonPath)) {
            $content = File::get($daemonPath);
            $content = preg_replace('/\s*public function setServer\([^}]+}\n?/s', '', $content);
            $content = preg_replace('/\s*public function getProtocolStats\([^}]+}\n?/s', '', $content);
            
            File::put($daemonPath, $content);
            $this->info('   ✓ Removed methods from DaemonServerRepository.php');
        }

        $kernelPath = base_path('app/Console/Kernel.php');
        if (File::exists($kernelPath)) {
            $content = File::get($kernelPath);

            $content = preg_replace('/\s*\$schedule->command\(\'p:server:collect\'\)->everyMinute\(\)->withoutOverlapping\(\);/', '', $content);
            
            File::put($kernelPath, $content);
            $this->info('   ✓ Removed schedule from Kernel.php');
        }

        $routesPath = base_path('routes/api-client.php');
        if (File::exists($routesPath)) {
            $content = File::get($routesPath);

            $content = preg_replace('/\s*Route::get\(\'\/protocols\',.*?\);/', '', $content);
            $content = preg_replace('/\s*Route::get\(\'\/statistics\',.*?\);/', '', $content);
            $content = preg_replace('/\s*Route::get\(\'\/nsm\',.*?\);/', '', $content);
            $content = preg_replace('/\s*Route::post\(\'\/statistics\',.*?\);/', '', $content);
            
            File::put($routesPath, $content);
            $this->info('   ✓ Removed routes from api-client.php');
        }
        $tsRoutesPath = base_path('resources/scripts/routers/routes.ts');
        if (File::exists($tsRoutesPath)) {
            $content = File::get($tsRoutesPath);

            $content = preg_replace('/\s*import StatisticsContainer.*?;/', '', $content);

            $content = preg_replace('/\s*{\s*path:\s*\'\/Statistics\'.*?},/s', '', $content);
            
            File::put($tsRoutesPath, $content);
            $this->info('   ✓ Removed TypeScript components');
        }

        $this->newLine();
        $this->info('   ⟳ Running post-uninstallation tasks...');

        $this->line('   ⟳ Clearing application cache...');
        $this->call('optimize:clear');

        $this->line('   ⟳ Restarting services...');
        $process = Process::fromShellCommandline('service pteroq restart');
        $process->run(function ($type, $buffer) {
            $this->line('     ' . trim($buffer));
        });

        $this->newLine();
        $this->warn('Frontend Rebuild Required');
        $this->line('   To complete the uninstallation, rebuild the frontend:');
        $this->line('   • cd /var/www/pterodactyl');
        $this->line('   • yarn build:production');
        
        $this->newLine();
        $this->info('   ✓ Network Statistics Manager has been removed successfully!');
        $this->newLine();

        return 0;
    }
}
