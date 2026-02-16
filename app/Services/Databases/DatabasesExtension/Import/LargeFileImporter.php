<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Import;

use Exception;
use PDO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Exceptions\Services\Database\DatabaseImportException;
use Illuminate\Support\Facades\Crypt;

class LargeFileImporter
{
    private const CHUNK_SIZE = 1048576;
    private const TEMP_DIR = '/tmp/pterodactyl_imports';

    public function import(Database $database, string $filePath, string $mode = 'merge', bool $force = false): bool
    {
        $fileSize = filesize($filePath);
        error_log("LARGE FILE IMPORT: Starting import of {$fileSize} byte file");

        try {
            if (!is_dir(self::TEMP_DIR)) {
                mkdir(self::TEMP_DIR, 0755, true);
            }

            $processedFile = $this->preprocessLargeFile($filePath, $force);

            $result = $this->directMysqlImport($database, $processedFile, $mode);

            return $result;

        } catch (Exception $e) {
            error_log("LARGE FILE IMPORT ERROR: " . $e->getMessage());
            throw new DatabaseImportException('Large file import failed: ' . $e->getMessage());
        } finally {
            if (isset($processedFile) && file_exists($processedFile)) {
                unlink($processedFile);
            }
        }
    }

    private function preprocessLargeFile(string $inputFile, bool $force): string
    {
        $outputFile = self::TEMP_DIR . '/processed_' . uniqid() . '.sql';
        
        $inputHandle = fopen($inputFile, 'r');
        $outputHandle = fopen($outputFile, 'w');
        
        if (!$inputHandle || !$outputHandle) {
            throw new DatabaseImportException('Cannot open files for preprocessing');
        }

        try {
            fwrite($outputHandle, "-- Optimized settings for large import\n");
            fwrite($outputHandle, "SET SESSION sql_mode = '';\n");
            fwrite($outputHandle, "SET FOREIGN_KEY_CHECKS = 0;\n");
            fwrite($outputHandle, "SET UNIQUE_CHECKS = 0;\n");
            fwrite($outputHandle, "SET AUTOCOMMIT = 0;\n");
            fwrite($outputHandle, "SET SESSION wait_timeout = 28800;\n");
            fwrite($outputHandle, "SET SESSION interactive_timeout = 28800;\n");
            fwrite($outputHandle, "SET SESSION max_allowed_packet = 1073741824;\n");
            fwrite($outputHandle, "START TRANSACTION;\n\n");

            $buffer = '';
            $lineNumber = 0;
            $processedLines = 0;

            while (!feof($inputHandle)) {
                $chunk = fread($inputHandle, self::CHUNK_SIZE);
                if ($chunk === false) break;

                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos + 1);
                    $buffer = substr($buffer, $pos + 1);
                    $lineNumber++;

                    $processedLine = $this->processLine($line, $force);
                    if ($processedLine !== null) {
                        fwrite($outputHandle, $processedLine);
                        $processedLines++;
                    }

                    if ($lineNumber % 10000 === 0) {
                        error_log("PREPROCESSING: Processed {$lineNumber} lines, kept {$processedLines}");
                        gc_collect_cycles();
                    }
                }
            }

            if (!empty($buffer)) {
                $processedLine = $this->processLine($buffer, $force);
                if ($processedLine !== null) {
                    fwrite($outputHandle, $processedLine);
                }
            }

            fwrite($outputHandle, "\nCOMMIT;\n");
            fwrite($outputHandle, "SET FOREIGN_KEY_CHECKS = 1;\n");
            fwrite($outputHandle, "SET UNIQUE_CHECKS = 1;\n");
            fwrite($outputHandle, "SET AUTOCOMMIT = 1;\n");

            error_log("PREPROCESSING COMPLETED: {$lineNumber} lines processed, {$processedLines} lines kept");

        } finally {
            fclose($inputHandle);
            fclose($outputHandle);
        }

        return $outputFile;
    }

    private function processLine(string $line, bool $force): ?string
    {
        $trimmedLine = trim($line);

        if (empty($trimmedLine)) {
            return $line;
        }

        if (str_starts_with($trimmedLine, '--') || str_starts_with($trimmedLine, '#')) {
            if (strpos($trimmedLine, 'phpMyAdmin') !== false || 
                strpos($trimmedLine, 'Table structure') !== false ||
                strpos($trimmedLine, 'Dumping data') !== false) {
                return $line;
            }
            return null;
        }

        if (preg_match('/\/\*!\d+/', $trimmedLine)) {
            $line = preg_replace('/\/\*!\d+\s+([^*]+)\s+\*\//', '$1', $line);
            $line = preg_replace('/\/\*!\d+[^*]*\*\//', '', $line);
        }

        $line = $this->applyQuickFixes($line);

        return $line;
    }

    private function applyQuickFixes(string $line): string
    {
        $line = str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $line);
        
        $line = str_replace("'0000-00-00 00:00:00'", 'NULL', $line);
        $line = str_replace("'0000-00-00'", 'NULL', $line);
        
        $line = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s+/', '', $line);
        
        return $line;
    }

    private function directMysqlImport(Database $database, string $sqlFile, string $mode): bool
    {
        $host = $database->host;
        $password = Crypt::decrypt($host->password);

        if ($mode === 'wipe') {
            $this->wipeDatabaseTables($database);
        }

        $command = sprintf(
            'mysql --max-allowed-packet=1G --net-buffer-length=32K --connect-timeout=60 --wait-timeout=28800 --interactive-timeout=28800 -h %s -P %d -u %s -p%s %s < %s',
            escapeshellarg($host->host),
            $host->port,
            escapeshellarg($host->username),
            escapeshellarg($password),
            escapeshellarg($database->database),
            escapeshellarg($sqlFile)
        );

        error_log("LARGE FILE IMPORT: Executing MySQL command");
        error_log("Command: " . preg_replace('/-p[^\s]+/', '-p***', $command));

        $result = Process::timeout(7200)->run($command);

        if ($result->successful()) {
            error_log("LARGE FILE IMPORT: MySQL import completed successfully");
            return true;
        } else {
            $error = $result->errorOutput() ?: $result->output();
            error_log("LARGE FILE IMPORT: MySQL import failed: " . $error);
            throw new DatabaseImportException("Large file MySQL import failed: " . $error);
        }
    }

    private function wipeDatabaseTables(Database $database): void
    {
        try {
            $dynamic = app(\Pterodactyl\Extensions\SqlDynDatabaseConnection::class);
            $dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();

            $connection->exec("SET FOREIGN_KEY_CHECKS = 0");

            $stmt = $connection->prepare("SHOW TABLES");
            $stmt->execute();
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $connection->exec("DROP TABLE IF EXISTS `{$table}`");
            }

            $connection->exec("SET FOREIGN_KEY_CHECKS = 1");

            error_log("LARGE FILE IMPORT: Wiped {count($tables)} tables");

        } catch (\Exception $e) {
            error_log("WIPE ERROR: " . $e->getMessage());
        }
    }
}