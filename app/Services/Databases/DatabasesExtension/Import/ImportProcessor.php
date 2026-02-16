<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Import;

use Exception;
use PDO;
use PDOException;
use Pterodactyl\Models\Database;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Exceptions\Service\Database\DatabaseImportException;

trait ImportProcessor
{
    protected function executeImportFromFile(UploadedFile $file, Database $database, PDO $connection): void
    {
        $originalMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '128M');
        
        try {
            $connection->setAttribute(PDO::ATTR_TIMEOUT, 3600);
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $connection->exec("USE `{$database->database}`");
            $connection->exec("SET FOREIGN_KEY_CHECKS=0");
            $connection->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
            $connection->exec("SET AUTOCOMMIT = 0");
            
            $this->processFileStreamMicro($file, $connection);
            
        } finally {
            ini_set('memory_limit', $originalMemoryLimit);
            try {
                $connection->exec("SET FOREIGN_KEY_CHECKS=1");
                $connection->exec("SET AUTOCOMMIT = 1");
            } catch (Exception $e) {
                logger()->warning('Cleanup failed', ['error' => $e->getMessage()]);
            }
        }
    }

    protected function processFileStreamMicro(UploadedFile $file, PDO $connection): void
    {
        $filename = strtolower($file->getClientOriginalName());
        $isGzip = str_ends_with($filename, '.gz');

        if ($isGzip) {
            $handle = gzopen($file->getPathname(), 'rb');
            if (!$handle) throw new DatabaseImportException('Cannot open gzip file');
        } else {
            $handle = fopen($file->getPathname(), 'rb');
            if (!$handle) throw new DatabaseImportException('Cannot open file');
        }
        
        $buffer = '';
        $statementCount = 0;
        $inString = false;
        $quote = '';
        
        try {
            while (!feof($handle)) {
                $chunk = $isGzip ? gzread($handle, 512) : fread($handle, 512);
                if ($chunk === false || $chunk === '') break;

                for ($i = 0; $i < strlen($chunk); $i++) {
                    $char = $chunk[$i];

                    if (!$inString && ($char === "'" || $char === '"')) {
                        $inString = true;
                        $quote = $char;
                    } elseif ($inString && $char === $quote && ($i === 0 || $chunk[$i-1] !== '\\')) {
                        $inString = false;
                    }
                    
                    $buffer .= $char;

                    if (strlen($buffer) > 16384) { 
                        if (!$inString && strpos($buffer, ';') === false) {
                            $buffer = '';
                            continue;
                        }
                    }

                    if (!$inString && $char === ';') {
                        $statement = trim($buffer);
                        $buffer = '';
                        
                        if (!empty($statement) && !str_starts_with($statement, '--')) {
                            if (strlen($statement) > 8192) { 
                                continue;
                            }
                            
                            try {
                                $connection->exec($statement);
                                $statementCount++;

                                if ($statementCount % 25 === 0) {
                                    $connection->exec("COMMIT; START TRANSACTION");
                                    gc_collect_cycles(); 
                                }
                                
                            } catch (PDOException $e) {
                            }
                        }
                        
                        unset($statement); 
                    }
                }
                
                unset($chunk);

                if ($statementCount % 50 === 0) {
                    gc_collect_cycles();
                }
            }

            $connection->exec("COMMIT");
            
        } finally {
            if ($isGzip) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
        }

    }

    protected function wipeDatabaseTables(Database $database, PDO $connection): void
    {
        $connection->exec("SET FOREIGN_KEY_CHECKS=0");
        
        $tables = $connection->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $connection->exec("DROP TABLE IF EXISTS `{$table}`");
        }
        
        $connection->exec("SET FOREIGN_KEY_CHECKS=1");
    }
}