<?php

namespace Pterodactyl\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Pterodactyl\Models\Database;
use Pterodactyl\Services\Databases\DatabaseImportExportService;

class DatabaseImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 1;
    
    protected Database $database;
    protected string $filePath;
    protected string $mode;
    protected bool $force;
    protected int $userId;
    protected string $jobId;

    public function __construct(Database $database, string $filePath, string $mode = 'merge', bool $force = false, int $userId = null)
    {
        $this->database = $database;
        $this->filePath = $filePath;
        $this->mode = $mode;
        $this->force = $force;
        $this->userId = $userId ?? 0;
        $this->jobId = uniqid('import_', true);
        
        $this->onQueue('database-imports');
    }

    public function handle(DatabaseImportExportService $importService): void
    {
        try {
            $this->updateJobStatus('processing', 'Import started');

            $uploadedFile = new \Illuminate\Http\Testing\File(
                basename($this->filePath),
                fopen($this->filePath, 'r')
            );

            $result = $importService->importDatabase($this->database, $uploadedFile, $this->mode, $this->force);

            if ($result) {
                $this->updateJobStatus('completed', 'Import completed successfully');
            } else {
                throw new Exception('Import returned false');
            }

        } catch (Exception $e) {
            $this->updateJobStatus('failed', 'Import failed: ' . $e->getMessage());
            
            Log::error("Background database import failed", [
                'job_id' => $this->jobId,
                'database_id' => $this->database->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        } finally {
            if (file_exists($this->filePath)) {
                unlink($this->filePath);
            }
        }
    }

    public function failed(Exception $exception): void
    {
        $this->updateJobStatus('failed', 'Import failed: ' . $exception->getMessage());
        
        Log::error("Database import job failed", [
            'job_id' => $this->jobId,
            'database_id' => $this->database->id,
            'error' => $exception->getMessage()
        ]);

        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }

    private function updateJobStatus(string $status, string $message): void
    {
        $statusData = [
            'job_id' => $this->jobId,
            'status' => $status,
            'message' => $message,
            'database_id' => $this->database->id,
            'updated_at' => now()->toISOString()
        ];

        cache()->put("import_job_{$this->jobId}", $statusData, 86400);
        
        if ($this->userId) {
            $userJobs = cache()->get("user_import_jobs_{$this->userId}", []);
            $userJobs[$this->jobId] = $statusData;
            cache()->put("user_import_jobs_{$this->userId}", $userJobs, 86400);
        }
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }
}