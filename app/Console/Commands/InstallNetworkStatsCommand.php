<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class InstallNetworkStatsCommand extends Command
{
    protected $signature = 'tmxkzi:network:install';
    protected $description = 'Install Network Statistics Manager addon';

    public function handle()
    {
        $this->newLine();
        $this->info('╔═════════════════════════════════════════╗');
        $this->info('║   Network Statistics Manager Install   ║');
        $this->info('╚═════════════════════════════════════════╝');
        $this->newLine();
        $this->warn('⚠  ATTENTION');
        $this->line('   This installer will modify several files in your Pterodactyl');
        $this->line('   installation. It is recommended to backup your files before');
        $this->line('   proceeding.');
        $this->newLine();
        $this->info('Files to be modified:');
        $this->line('   • app/Repositories/Daemon/DaemonServerRepository.php');
        $this->line('   • app/Console/Kernel.php');
        $this->line('   • routes/api-client.php');
        $this->line('   • resources/scripts/routers/routes.ts');
        $this->newLine();
        
        $this->line('   To proceed with the installation, please confirm below.');
        if (!$this->confirm('   Ready to proceed with the installation?', false)) {
            $this->newLine();
            $this->info('   ✖ Installation cancelled.');
            return 0;
        }
        $this->newLine();
        $this->info('   ⟳ Installing Network Statistics Manager...');
        $this->newLine();
        $daemonPath = base_path('app/Repositories/Wings/DaemonServerRepository.php');
        if (!File::exists($daemonPath)) {
            $this->error('Could not find DaemonServerRepository.php');
            return 1;
        }
        $content = File::get($daemonPath);
        $hasSetServer = str_contains($content, 'public function setServer(Server $server): self');
        $hasProtocolStats = str_contains($content, 'public function getProtocolStats(): array');
        
        if ($hasSetServer && $hasProtocolStats) {
            $this->info('All required methods already exist in DaemonServerRepository.php');
        } else {
            $methodsToAdd = '';

            if (!$hasSetServer) {
                $methodsToAdd .= <<<'EOD'

    /**
     * Sets the server instance for this repository.
     *
     * @throws \Webmozart\Assert\InvalidArgumentException
     */
    public function setServer(Server $server): self
    {
        $this->server = $server;
        $this->setNode($this->server->node); 
        return $this;
    }
EOD;
            }

            if (!$hasProtocolStats) {
                $methodsToAdd .= <<<'EOD'

    /**
     * Get protocol-specific network statistics from Wings.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function getProtocolStats(): array
    {
        Assert::isInstanceOf($this->server, Server::class);

        try {
            $response = $this->getHttpClient()->get(
                sprintf('/api/servers/%s/stats/protocols', $this->server->uuid)
            );

            return json_decode($response->getBody()->getContents(), true);
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }
EOD;
            }

            if (!empty($methodsToAdd)) {
                $lastBrace = strrpos($content, '}');
                if ($lastBrace === false) {
                    $this->error('Could not find proper position in DaemonServerRepository.php');
                    return 1;
                }

                $newContent = substr_replace($content, $methodsToAdd . "\n", $lastBrace, 0);
                File::put($daemonPath, $newContent);
                $this->info('Added missing methods to DaemonServerRepository.php: ' . 
                    (!$hasSetServer ? 'setServer ' : '') . 
                    (!$hasProtocolStats ? 'getProtocolStats' : ''));
            }
        }

        $methodsToAdd = '';

        if (!$hasSetServer) {
            $methodsToAdd .= <<<'EOD'

    /**
     * Sets the server instance for this repository.
     *
     * @throws \Webmozart\Assert\InvalidArgumentException
     */
    public function setServer(Server $server): self
    {
        $this->server = $server;
        $this->setNode($this->server->node); 
        return $this;
    }
EOD;
        }

        if (!$hasProtocolStats) {
            $methodsToAdd .= <<<'EOD'

    /**
     * Get protocol-specific network statistics from Wings.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function getProtocolStats(): array
    {
        Assert::isInstanceOf($this->server, Server::class);

        try {
            $response = $this->getHttpClient()->get(
                sprintf('/api/servers/%s/stats/protocols', $this->server->uuid)
            );

            return json_decode($response->getBody()->getContents(), true);
        } catch (TransferException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }
EOD;
        }

        if (!empty($methodsToAdd)) {
            $lastBrace = strrpos($content, '}');
            if ($lastBrace === false) {
                $this->error('Could not find proper position in DaemonServerRepository.php');
                return 1;
            }

            $newContent = substr_replace($content, $methodsToAdd . "\n", $lastBrace, 0);
            File::put($daemonPath, $newContent);

            $this->info('Successfully modified DaemonServerRepository.php');
            $this->info('Added missing methods: ' . 
                (!$hasSetServer ? 'setServer ' : '') . 
                (!$hasProtocolStats ? 'getProtocolStats' : ''));
        }

        $kernelPath = base_path('app/Console/Kernel.php');
        if (!File::exists($kernelPath)) {
            $this->error('Could not find Kernel.php');
            return 1;
        }

        $kernelContent = File::get($kernelPath);
        $hasScheduleCommand = str_contains($kernelContent, "command('p:server:collect')") || 
                             str_contains($kernelContent, "->everySecond()") && 
                             str_contains($kernelContent, "NetworkStatisticSetting::getCollectionInterval()") && 
                             str_contains($kernelContent, "pterodactyl:panic-mode:check-bandwidth");

        if (!str_contains($kernelContent, 'use Pterodactyl\\Models\\NetworkStatisticSetting;')) {
            $importPattern = "use Pterodactyl\\Console\\Commands\\Maintenance\\CleanServiceBackupFilesCommand;";
            $importPosition = strpos($kernelContent, $importPattern);
            
            if ($importPosition !== false) {
                $importPosition += strlen($importPattern);
                $newImport = "\nuse Pterodactyl\\Models\\NetworkStatisticSetting;";
                $kernelContent = substr_replace($kernelContent, $newImport, $importPosition, 0);
                $this->info('Added NetworkStatisticSetting import to Kernel.php');
            }
        }
        
        if ($hasScheduleCommand) {
            $this->info('Schedule command already exists in Kernel.php');
        } else {
            $searchPattern = "        if (config('activity.prune_days')) {\n            \$schedule->command(PruneCommand::class, ['--model' => [ActivityLog::class]])->daily();\n        }";
            $position = strpos($kernelContent, $searchPattern);

            if ($position === false) {
                $this->error('Could not find ActivityLog schedule in Kernel.php. Please make sure the following code exists:\n' . $searchPattern);
                return 1;
            }

            $position += strlen($searchPattern);
            $newSchedule = "\n\n        \$schedule->call(function() {\n            try {\n                \$interval = NetworkStatisticSetting::getCollectionInterval();\n                \$lastRun = cache()->get('last_statistics_collection');\n                \$now = now()->timestamp;\n                \n                if (!\$lastRun || (\$now - \$lastRun) >= \$interval) {\n                    \\Illuminate\\Support\\Facades\\Artisan::call('p:server:collect');\n                    cache()->put('last_statistics_collection', \$now, 3600);\n                }\n            } catch (\\Exception \$e) {\n                \\Illuminate\\Support\\Facades\\Artisan::call('p:server:collect');\n            }\n        })->everySecond();\n\n        \$schedule->command('pterodactyl:panic-mode:check-bandwidth')->everyMinute()->withoutOverlapping();";
            $kernelContent = substr_replace($kernelContent, $newSchedule, $position, 0);
            
            File::put($kernelPath, $kernelContent);
            $this->info('Successfully added schedule command to Kernel.php');
        }
        $routesPath = base_path('routes/api-client.php');
        if (!File::exists($routesPath)) {
            $this->error('Could not find api-client.php');
            return 1;
        }

        $routesContent = File::get($routesPath);
        $routes = [
            "    Route::get('/protocols', [Client\\Servers\\NetworkStatsController::class, 'protocols'])->name('api:client:server.network.protocols');",
            "    Route::get('/statistics', [Client\\Servers\\ServerStatisticsController::class, 'index'])->name('api:client:server.statistics');",
            "    Route::get('/nsm', [Client\\Servers\\ServerStatisticsController::class, 'resources'])->name('api:client:server.stats.resources');",
            "    Route::post('/statistics', [Client\\Servers\\ServerStatisticsController::class, 'store'])->name('api:client:server.statistics.store');"
        ];

        $missingRoutes = [];
        foreach ($routes as $route) {
            if (!str_contains($routesContent, $route)) {
                $missingRoutes[] = $route;
            }
        }

        if (empty($missingRoutes)) {
            $this->info('All routes already exist in api-client.php');
        } else {
            $searchPattern = "Route::get('/resources', Client\Servers\ResourceUtilizationController::class)->name('api:client:server.resources');";
            $position = strpos($routesContent, $searchPattern);

            if ($position === false) {
                $this->error('Could not find resources route in api-client.php. Please make sure the following code exists:\n' . $searchPattern);
                return 1;
            }

            $position += strlen($searchPattern);
            
            $newRoutes = "\n\n" . implode("\n", $missingRoutes);
            $routesContent = substr_replace($routesContent, $newRoutes, $position, 0);
            File::put($routesPath, $routesContent);
            $this->info('Successfully added ' . count($missingRoutes) . ' missing route(s) to api-client.php');
        }

        $tsRoutesPath = base_path('resources/scripts/routers/routes.ts');
        if (!File::exists($tsRoutesPath)) {
            $this->error('Could not find routes.ts');
            return 1;
        }

        $tsContent = File::get($tsRoutesPath);
        $modified = false;
        $importLine = "import StatisticsContainer from '@/components/server/devContainers/Statistics/statisticsContainer';";
        if (!str_contains($tsContent, $importLine)) {
            $searchPattern = "import NetworkContainer from '@/components/server/network/NetworkContainer';";
            $position = strpos($tsContent, $searchPattern);

            if ($position === false) {
                preg_match_all('/^import .+$/m', $tsContent, $matches, PREG_OFFSET_CAPTURE);
                if (!empty($matches[0])) {
                    $lastImport = end($matches[0]);
                    $position = $lastImport[1] + strlen($lastImport[0]);
                } else {
                    $position = 0;
                }
            } else {
                $position += strlen($searchPattern);
            }
            $tsContent = substr_replace($tsContent, ($position > 0 ? "\n" : "") . $importLine . "\n", $position, 0);
            $modified = true;
            $this->info('Added import to routes.ts');
        }
        $routeConfig = "        {\n            path: '/Statistics',\n            permission: 'allocation.*',\n            name: 'Statistics',\n            component: StatisticsContainer,\n            exact: true,\n        },";

        if (!str_contains($tsContent, "path: '/Statistics'")) {
            $searchPattern = "        {\n            path: '/network',\n            permission: 'allocation.*',\n            name: 'Network',\n            component: NetworkContainer,\n        },";
            $position = strpos($tsContent, $searchPattern);

            if ($position === false) {
                $patterns = [
                    '/const\s+routes\s*=\s*\[/m',
                    '/export\s+const\s+routes\s*=\s*\[/m', 
                    '/export\s+default\s*\[/m',  
                    '/routes\s*:\s*\[/m' 
                ];
                
                $found = false;
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $tsContent, $matches, PREG_OFFSET_CAPTURE)) {
                        $position = $matches[0][1] + strlen($matches[0][0]);
                        $routeConfig = "\n" . $routeConfig;
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    if (preg_match('/path:\s*[\'"]\/[^\'"]+[\'"]/', $tsContent, $matches, PREG_OFFSET_CAPTURE)) {
                        $routeStart = strrpos(substr($tsContent, 0, $matches[0][1]), '{');
                        if ($routeStart !== false) {
                            $routeEnd = strpos($tsContent, '},', $matches[0][1]);
                            if ($routeEnd !== false) {
                                $position = $routeEnd + 2;
                                $routeConfig = "\n" . $routeConfig;
                                $found = true;
                            }
                        }
                    }
                }
                
                if (!$found) {
                    $this->warn('Could not find routes array in routes.ts');
                    $this->line('Will attempt to continue installation, but you may need to manually add the route:');
                    $this->line($routeConfig);
                    $this->line("\nTo your routes.ts file in the routes array.");
                    $modified = false;
                }
            } else {
                $position += strlen($searchPattern);
                $routeConfig = "\n" . $routeConfig;
            }
            $tsContent = substr_replace($tsContent, $routeConfig, $position, 0);
            $modified = true;
            $this->info('Added route configuration to routes.ts');
        }

        if ($modified) {
            File::put($tsRoutesPath, $tsContent);
            $this->info('✓ Successfully updated routes.ts');
        } else {
            $this->info('✓ All TypeScript changes already exist in routes.ts');
        }
        $adminRoutesPath = base_path('routes/admin.php');
        if (!File::exists($adminRoutesPath)) {
            $this->error('Could not find admin.php routes file');
            return 1;
        }
        
        $adminRoutesContent = File::get($adminRoutesPath);
        $panicModeRoutes = "/*\n|--------------------------------------------------------------------------\n| Panic Mode Controller Routes\n|--------------------------------------------------------------------------\n|\n| Endpoint: /admin/panicmode\n|\n*/\nRoute::group(['prefix' => 'panicmode'], function () {\n    Route::get('/', [Admin\\PanicModeController::class, 'index'])->name('admin.panicmode');\n    Route::post('/', [Admin\\PanicModeController::class, 'update'])->name('admin.panicmode.update');\n    Route::post('/test-webhook', [Admin\\PanicModeController::class, 'testWebhook'])->name('admin.panicmode.test-webhook');\n    Route::post('/manual-check', [Admin\\PanicModeController::class, 'manualCheck'])->name('admin.panicmode.manual-check');\n});";
        
        $networkStatsRoutes = "/*\n|--------------------------------------------------------------------------\n| Network Statistics Controller Routes\n|--------------------------------------------------------------------------\n|\n| Endpoint: /admin/network-statistics\n|\n*/\nRoute::group(['prefix' => 'network-statistics'], function () {\n    Route::get('/', [Admin\\NetworkStatisticsController::class, 'index'])->name('admin.network.stats');\n    Route::get('/intro', [Admin\\NetworkStatisticsController::class, 'intro'])->name('admin.statistics.intro');\n});";
        
        $networkSettingsRoutes = "/*\n|--------------------------------------------------------------------------\n| Network Settings Controller Routes\n|--------------------------------------------------------------------------\n|\n| Endpoint: /admin/networksettings\n|\n*/\nRoute::group(['prefix' => 'networksettings'], function () {\n    Route::get('/', [Admin\\NetworkSettingsController::class, 'index'])->name('admin.networksettings');\n    Route::post('/', [Admin\\NetworkSettingsController::class, 'update'])->name('admin.networksettings.update');\n    Route::post('/clear', [Admin\\NetworkSettingsController::class, 'clearStatistics'])->name('admin.networksettings.clear');\n    Route::post('/collect', [Admin\\NetworkSettingsController::class, 'collectAllStatistics'])->name('admin.networksettings.collect');\n});";
        
        $routesAdded = false;
        if (!str_contains($adminRoutesContent, "Route::group(['prefix' => 'panicmode']")) {
            $adminRoutesContent .= "\n\n" . $panicModeRoutes;
            $routesAdded = true;
            $this->info('Added Panic Mode routes to admin.php');
        }
        
        if (!str_contains($adminRoutesContent, "Route::group(['prefix' => 'network-statistics']")) {
            $adminRoutesContent .= "\n\n" . $networkStatsRoutes;
            $routesAdded = true;
            $this->info('Added Network Statistics routes to admin.php');
        }
        
        if (!str_contains($adminRoutesContent, "Route::group(['prefix' => 'networksettings']")) {
            $adminRoutesContent .= "\n\n" . $networkSettingsRoutes;
            $routesAdded = true;
            $this->info('Added Network Settings routes to admin.php');
        }
        
        if ($routesAdded) {
            File::put($adminRoutesPath, $adminRoutesContent);
            $this->info('✓ Successfully updated admin routes');
        } else {
            $this->info('✓ All admin routes already exist');
        }
        $adminLayoutPath = base_path('resources/views/layouts/admin.blade.php');
        if (!File::exists($adminLayoutPath)) {
            $this->error('Could not find admin.blade.php layout file');
            return 1;
        }
        
        $adminLayoutContent = File::get($adminLayoutPath);
        $menuItems = "<li class=\"header\">NSM V2</li>\n                        <li class=\"{{ ! starts_with(Route::currentRouteName(), 'admin.networksettings') ?: 'active' }}\">\n                            <a href=\"{{ route('admin.networksettings') }}\">\n                                <i class=\"fa fa-cogs\"></i> <span>Network Settings</span>\n                            </a>\n                        </li>\n                        <li class=\"{{ ! starts_with(Route::currentRouteName(), 'admin.panicmode') ?: 'active' }}\">\n                            <a href=\"{{ route('admin.panicmode') }}\">\n                                <i class=\"fa fa-exclamation-triangle\"></i> <span>Panic Mode</span>\n                            </a>\n                        </li>\n                        <li class=\"{{ ! starts_with(Route::currentRouteName(), 'admin.network.stats') ?: 'active' }}\">\n                            <a href=\"{{ route('admin.network.stats') }}\">\n                                <i class=\"fa fa-bar-chart\"></i> <span>Network Statistics</span>\n                            </a>\n                        </li>";
        
        if (!str_contains($adminLayoutContent, "<li class=\"header\">NSM V2</li>")) {
            $searchPattern = "<li class=\"{{ ! starts_with(Route::currentRouteName(), 'admin.users') ?: 'active' }}\">\n                            <a href=\"{{ route('admin.users') }}\">\n                                <i class=\"fa fa-users\"></i> <span>Users</span>\n                            </a>\n                        </li>";
            
            $position = strpos($adminLayoutContent, $searchPattern);
            
            if ($position !== false) {
                $position += strlen($searchPattern);
                $adminLayoutContent = substr_replace($adminLayoutContent, "\n                        " . $menuItems, $position, 0);
                File::put($adminLayoutPath, $adminLayoutContent);
                $this->info('✓ Successfully added NSM V2 menu items to admin layout');
            } else {
                $this->warn('Could not find the Users menu item in admin layout');
                $this->line('You may need to manually add the NSM V2 menu items to resources/views/layouts/admin.blade.php');
            }
        } else {
            $this->info('✓ NSM V2 menu items already exist in admin layout');
        }
        
        $this->newLine();
        $this->info('╔═════════════════════════════════════════╗');
        $this->info('║     Running Post-Installation Steps     ║');
        $this->info('╚═════════════════════════════════════════╝');
        $this->newLine();
        $this->line('⟳  Running database migrations...');
        $this->call('migrate', ['--force' => true]);

        $this->line('⟳  Clearing application cache...');
        $this->call('optimize:clear');

        $this->line('⟳  Configuring system services...');
        $commands = [
            'service pteroq restart',
            'chown -R www-data:www-data /var/www/pterodactyl/*'
        ];

        foreach ($commands as $command) {
            $process = Process::fromShellCommandline($command);
            $process->run(function ($type, $buffer) {
                $this->line('  ' . trim($buffer));
            });

            if (!$process->isSuccessful()) {
                $this->error("✕ Failed to execute: {$command}");
                $this->error($process->getErrorOutput());
                return 1;
            }
        }

        $this->newLine();
        $this->info('✓ Backend installation completed successfully!');
        $this->newLine();
        $this->info('╔═════════════════════════════════════════╗');
        $this->info('║        Frontend Build Required         ║');
        $this->info('╚═════════════════════════════════════════╝');
        $this->newLine();
        $this->line('To complete the installation, we need to rebuild the frontend.');
        $this->line('This process requires:');
        $this->line('  • Node.js and npm installed');
        $this->line('  • Yarn package manager');
        $this->line('  • node_modules dependencies installed');
        $this->newLine();
        
        if (!$this->confirm('Ready to proceed with the frontend build?', false)) {
            $this->newLine();
            $this->warn('⚠ Frontend build skipped.');
            $this->line('To build the frontend later, run these commands:');
            $this->info('  cd /var/www/pterodactyl');
            $this->info('  yarn install');
            $this->info('  yarn build:production');
            return 0;
        }

        $this->info('\nRebuilding frontend...');
        $process = Process::fromShellCommandline('yarn build:production');
        $process->setTimeout(null);
        $process->run(function ($type, $buffer) {
            $this->line($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->error('Frontend rebuild failed!');
            $this->error($process->getErrorOutput());
            return 1;
        }

        $this->info('Network Statistics Manager installation completed successfully!');
        return 0;
    }
}
