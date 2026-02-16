<?php

namespace Pterodactyl\Console\Commands\SqlDashboard;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

class InstallCommand extends Command
{
    protected $signature = 'sql-dashboard:install {--force : Force installation even if already installed}';
    protected $description = 'Install SQL Dashboard extension with beautiful UI';

    private array $steps = [
        'routes' => 'Adding routes to api-client.php',
        'observer' => 'Registering Database observer',
        'frontend' => 'Updating frontend components',
        'optimize' => 'Optimizing application',
        'permissions' => 'Setting file permissions',
        'dependencies' => 'Installing dependencies',
        'build' => 'Building frontend assets'
    ];

    public function handle(): int
    {
        $this->showHeader();
        
        if (!$this->option('force') && $this->isAlreadyInstalled()) {
            $this->error('SQL Dashboard is already installed! Use --force to reinstall.');
            return 1;
        }

        $this->info('🚀 Starting SQL Dashboard installation...');
        $this->newLine();

        $progressBar = $this->output->createProgressBar(count($this->steps));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('Initializing...');
        $progressBar->start();

        try {
            foreach ($this->steps as $step => $message) {
                $progressBar->setMessage($message);
                $this->executeStep($step);
                $progressBar->advance();
                $this->newLine();
            }

            $progressBar->finish();
            $this->newLine(2);
            $this->showSuccessMessage();
            
            return 0;
        } catch (\Exception $e) {
            $progressBar->finish();
            $this->newLine(2);
            $this->error('❌ Installation failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function showHeader(): void
    {
        $this->line('<fg=cyan>╔══════════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=cyan>║</> <fg=yellow;options=bold>           SQL Dashboard Installation Wizard</fg=yellow;options=bold> <fg=cyan>           ║</>');
        $this->line('<fg=cyan>║</> <fg=white>        Advanced Database Management Extension</fg=white> <fg=cyan>        ║</>');
        $this->line('<fg=cyan>╚══════════════════════════════════════════════════════════════╝</>');
        $this->newLine();
    }

    private function isAlreadyInstalled(): bool
    {
        $apiClientPath = base_path('routes/api-client.php');
        if (!File::exists($apiClientPath)) {
            return false;
        }

        $content = File::get($apiClientPath);
        return str_contains($content, "require_once __DIR__ . '/sql-dashboard.php';");
    }

    private function executeStep(string $step): void
    {
        match($step) {
            'routes' => $this->addRoutes(),
            'observer' => $this->registerObserver(),
            'frontend' => $this->updateFrontend(),
            'optimize' => $this->optimizeApplication(),
            'permissions' => $this->setPermissions(),
            'dependencies' => $this->installDependencies(),
            'build' => $this->buildFrontend()
        };
    }

    private function addRoutes(): void
    {
        $apiClientPath = base_path('routes/api-client.php');
        
        if (!File::exists($apiClientPath)) {
            throw new \Exception('api-client.php not found in routes directory');
        }

        $content = File::get($apiClientPath);
        
        if (str_contains($content, "require_once __DIR__ . '/sql-dashboard.php';")) {
            $this->line('<fg=yellow>⚠️  Routes already added, skipping...</>');
            return;
        }

        $searchPattern = '/Route::group\(\[\s*\'prefix\'\s*=>\s*\'\/databases\'\s*\],\s*function\s*\(\)\s*\{[^}]*\}/s';
        
        if (!preg_match($searchPattern, $content)) {
            throw new \Exception('Could not find databases route group in api-client.php');
        }

        $replacement = preg_replace_callback($searchPattern, function($matches) {
            $routeGroup = $matches[0];
            if (str_contains($routeGroup, "require_once __DIR__ . '/sql-dashboard.php';")) {
                return $routeGroup;
            }
            return str_replace(
                'Route::delete(\'/{database}\', [Client\\Servers\\DatabaseController::class, \'delete\']);',
                "Route::delete('{database}', [Client\\Servers\\DatabaseController::class, 'delete']);\n        require_once __DIR__ . '/sql-dashboard.php';",
                $routeGroup
            );
        }, $content);

        File::put($apiClientPath, $replacement);
        $this->line('<fg=green>✅ Routes added to api-client.php</>');
    }

    private function registerObserver(): void
    {
        $eventServiceProviderPath = app_path('Providers/EventServiceProvider.php');
        
        if (!File::exists($eventServiceProviderPath)) {
            throw new \Exception('EventServiceProvider.php not found');
        }

        $content = File::get($eventServiceProviderPath);
        
        if (str_contains($content, '\\Pterodactyl\\Models\\Database::observe(static::class);')) {
            $this->line('<fg=yellow>⚠️  Observer already registered, skipping...</>');
            return;
        }

        $searchPattern = '/EggVariable::observe\(EggVariableObserver::class\);/';
        $replacement = "EggVariable::observe(EggVariableObserver::class);\n\n        \\Pterodactyl\\Models\\Database::observe(static::class);";

        $newContent = preg_replace($searchPattern, $replacement, $content);
        
        if ($newContent === $content) {
            throw new \Exception('Could not find EggVariable::observe line in EventServiceProvider');
        }

        File::put($eventServiceProviderPath, $newContent);
        $this->line('<fg=green>✅ Database observer registered</>');
    }

    private function updateFrontend(): void
    {
        $databaseRowPath = resource_path('scripts/components/server/databases/DatabaseRow.tsx');
        
        if (!File::exists($databaseRowPath)) {
            throw new \Exception('DatabaseRow.tsx not found');
        }

        $content = File::get($databaseRowPath);
        
        if (str_contains($content, 'import DatabaseActions from \'./DatabaseActions\';')) {
            $this->line('<fg=yellow>⚠️  Frontend already updated, skipping...</>');
            return;
        }

        $this->line('<fg=yellow>📝 Manual frontend update required:</>');
        $this->line('<fg=white>Please add the following to DatabaseRow.tsx:</>');
        $this->newLine();
        
        $this->line('<fg=cyan>1. Add import after CopyOnClick import:</>');
        $this->line('<fg=green>   import DatabaseActions from \'./DatabaseActions\';</>');
        $this->newLine();
        
        $this->line('<fg=cyan>2. Add component before the delete button:</>');
        $this->line('<fg=green>   <DatabaseActions database={database} /></>');
        $this->newLine();
        
        if (!$this->confirm('Have you completed the frontend updates?')) {
            throw new \Exception('Frontend update cancelled by user');
        }
        
        $this->line('<fg=green>✅ Frontend update confirmed</>');
    }

    private function optimizeApplication(): void
    {
        $this->line('<fg=blue>🔄 Optimizing application...</>');
        
        Artisan::call('migrate:status');
        Artisan::call('optimize:clear');
        
        $this->line('<fg=green>✅ Application optimized</>');
    }

    private function setPermissions(): void
    {
        $this->line('<fg=blue>🔐 Setting file permissions...</>');
        
        $commands = [
            'chown -R www-data:www-data /var/www/pterodactyl/*',
            'chmod -R 755 storage/* bootstrap/cache/'
        ];
        
        foreach ($commands as $command) {
            $this->line("<fg=gray>Running: {$command}</>");
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                $this->line("<fg=yellow>⚠️  Command failed (this may be normal): {$command}</>");
            }
        }
        
        $this->line('<fg=green>✅ Permissions set</>');
    }

    private function installDependencies(): void
    {
        $this->line('<fg=blue>📦 Installing dependencies...</>');
        
        $command = 'composer require phpmyadmin/sql-parser';
        $this->line("<fg=gray>Running: {$command}</>");
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception('Failed to install composer dependencies');
        }
        
        $this->line('<fg=green>✅ Dependencies installed</>');
    }

    private function buildFrontend(): void
    {
        $this->line('<fg=blue>🏗️  Building frontend assets...</>');
        
        $command = 'yarn build:production';
        $this->line("<fg=gray>Running: {$command}</>");
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception('Failed to build frontend assets');
        }
        
        $this->line('<fg=green>✅ Frontend built successfully</>');
    }

    private function showSuccessMessage(): void
    {
        $this->line('<fg=green>╔══════════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=green>║</> <fg=white;options=bold>                    🎉 Installation Complete! 🎉</fg=white;options=bold> <fg=green>                    ║</>');
        $this->line('<fg=green>╚══════════════════════════════════════════════════════════════╝</>');
        $this->newLine();
        
        $this->line('<fg=yellow;options=bold>✨ SQL Dashboard is now ready to use!</>');
        $this->newLine();
        
        $this->line('<fg=cyan>🚀 Features installed:</>');
        $this->line('<fg=white>   • Database Import/Export with compression support</>');
        $this->line('<fg=white>   • Selective table and column imports</>');
        $this->line('<fg=white>   • Database presets for common schemas</>');
        $this->line('<fg=white>   • Index analyzer for performance optimization</>');
        $this->line('<fg=white>   • SQL console for direct queries</>');
        $this->line('<fg=white>   • Health monitoring and analytics</>');
        $this->line('<fg=white>   • Advanced security protections</>');
        $this->newLine();
        
        $this->line('<fg=cyan>🔧 Next steps:</>');
        $this->line('<fg=white>   1. Refresh your browser</>');
        $this->line('<fg=white>   2. Go to any server\'s database section</>');
        $this->line('<fg=white>   3. Look for the new action buttons</>');
        $this->newLine();
        
        $this->line('<fg=yellow>💡 Tip: Check the database content modal for advanced features!</>');
    }
}