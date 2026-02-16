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

class DatabaseExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800;
    public $tries = 3;

    public function __construct(
        public Database $database,
        public string $format,
        public string $filename,
        public int $userId,
        public array $options = []
    ) {
        $this->onQueue('database-operations');
    }

    public function handle(DatabaseImportExportService $exportService): void
    {
        try {
            $sqlContent = $this->generateSqlDump();
            $finalContent = $this->compressContent($sqlContent, $this->format);
            $storagePath = "exports/{$this->database->server->uuid}/{$this->filename}";
            Storage::disk('local')->put($storagePath, $finalContent);
            unset($sqlContent, $finalContent);

        } catch (Exception $e) {
            Log::error('Database export job failed', [
                'database_id' => $this->database->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }

    private function generateSqlDump(): string
    {
        $host = $this->database->host;
        $decryptedPassword = \Illuminate\Support\Facades\Crypt::decrypt($this->database->password);
        
        $command = [
            'mysqldump',
            '-h' . $host->host,
            '-P' . $host->port,
            '-u' . $this->database->username,
            '-p' . $decryptedPassword,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--add-drop-table',
            '--disable-keys',
            '--extended-insert',
            '--quick',
            '--lock-tables=false',
            $this->database->database
        ];

        $result = \Illuminate\Support\Facades\Process::timeout(1200)->run($command);

        if ($result->successful()) {
            $output = $result->output();
            if (!empty(trim($output)) && 
                !preg_match('/<\s*html\s*>/i', $output) && 
                !preg_match('/<\s*!DOCTYPE\s+html/i', $output)) {
                return $output;
            }
        }

        return $this->generatePhpExport();
    }

    private function generatePhpExport(): string
    {
        try {
            $host = $this->database->host;
            $decryptedPassword = \Illuminate\Support\Facades\Crypt::decrypt($this->database->password);
            
            $dsn = "mysql:host={$host->host};port={$host->port};dbname={$this->database->database};charset=utf8mb4";
            $pdo = new \PDO($dsn, $this->database->username, $decryptedPassword, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
            ]);

            $output = '';
            $output .= "-- Database Export\n";
            $output .= "-- Generated on " . date('Y-m-d H:i:s') . "\n";
            $output .= "-- Database: {$this->database->database}\n\n";
            
            $output .= "SET FOREIGN_KEY_CHECKS=0;\n";
            $output .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
            $output .= "SET AUTOCOMMIT = 0;\n";
            $output .= "START TRANSACTION;\n\n";

            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $tableName) {
                $createStmt = $pdo->prepare("SHOW CREATE TABLE `{$tableName}`");
                $createStmt->execute();
                $createResult = $createStmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($createResult) {
                    $output .= "-- Table structure for `{$tableName}`\n";
                    $output .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
                    $output .= $createResult['Create Table'] . ";\n\n";
                }

                $dataStmt = $pdo->prepare("SELECT * FROM `{$tableName}`");
                $dataStmt->execute();

                $output .= "-- Data for table `{$tableName}`\n";
                while ($row = $dataStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $values = array_map(function($value) use ($pdo) {
                        return $value === null ? 'NULL' : $pdo->quote($value);
                    }, array_values($row));
                    
                    $columnNames = array_keys($row);
                    $output .= "INSERT INTO `{$tableName}` (`" . implode('`, `', $columnNames) . "`) VALUES (" . implode(', ', $values) . ");\n";
                }
                $output .= "\n";
            }

            $output .= "COMMIT;\n";
            $output .= "SET FOREIGN_KEY_CHECKS=1;\n";

            return $output;

        } catch (Exception $e) {
            throw new Exception('PHP export failed: ' . $e->getMessage());
        }
    }

    private function compressContent(string $content, string $format): string
    {
        switch ($format) {
            case 'gz':
                return gzencode($content, 9);
                
            case 'bz2':
                return bzcompress($content, 9);
                
            case 'zip':
                return $this->createZipArchive($content);
                
            case 'tar':
                return $this->createTarArchive($content);
                
            case '7z':
                return gzencode($content, 9);
                
            case 'rar':
                return gzencode($content, 9);
                
            default:
                return $content;
        }
    }

    private function createZipArchive(string $content): string
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZIP support not available');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'db_export_');
        $zip = new \ZipArchive();
        
        if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Cannot create ZIP archive');
        }

        $sqlFilename = pathinfo($this->filename, PATHINFO_FILENAME) . '.sql';
        $zip->addFromString($sqlFilename, $content);
        $zip->close();

        $zipContent = file_get_contents($tempFile);
        unlink($tempFile);

        return $zipContent;
    }

    private function createTarArchive(string $content): string
    {
        if (!class_exists('PharData')) {
            throw new Exception('TAR support not available');
        }

        $tempDir = sys_get_temp_dir() . '/db_export_' . uniqid();
        mkdir($tempDir);
        
        $sqlFile = $tempDir . '/' . pathinfo($this->filename, PATHINFO_FILENAME) . '.sql';
        file_put_contents($sqlFile, $content);

        $tarFile = $tempDir . '/archive.tar';
        $tar = new \PharData($tarFile);
        $tar->addFile($sqlFile, basename($sqlFile));

        $tarContent = file_get_contents($tarFile);
        
        unlink($sqlFile);
        unlink($tarFile);
        rmdir($tempDir);

        return $tarContent;
    }

    public function failed(Exception $exception): void
    {
        Log::error('Database export job failed permanently', [
            'database_id' => $this->database->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}