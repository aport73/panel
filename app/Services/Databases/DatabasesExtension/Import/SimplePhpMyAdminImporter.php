<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Import;

use Exception;
use PDO;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Database;
use Pterodactyl\Exceptions\Services\Database\DatabaseImportException;

class SimplePhpMyAdminImporter
{
    private const MAX_STATEMENT_SIZE = 1048576;
    private const COMMIT_INTERVAL = 100;

    public function import(Database $database, string $filePath, string $mode = 'merge', bool $force = false): bool
    {
        $originalMemoryLimit = ini_get('memory_limit');
        
        try {
            ini_set('memory_limit', '128M');
            
            $connection = $this->setupDatabaseConnection($database, $mode);
            
            $stats = $this->processFileWithStatementReconstruction($filePath, $connection, $force);
            
            $connection->exec("COMMIT");
            $this->restoreConnectionSettings($connection);
            
            error_log("SIMPLE IMPORT COMPLETED: {$stats['executed']} statements executed, {$stats['skipped']} skipped");
            
            return true;
            
        } catch (Exception $e) {
            error_log("SIMPLE IMPORT ERROR: " . $e->getMessage());
            
            try {
                if (isset($connection)) {
                    $connection->exec("ROLLBACK");
                    $this->restoreConnectionSettings($connection);
                }
            } catch (Exception $cleanupError) {
                error_log("CLEANUP ERROR: " . $cleanupError->getMessage());
            }
            
            throw new DatabaseImportException('Simple import failed: ' . $e->getMessage());
            
        } finally {
            ini_set('memory_limit', $originalMemoryLimit);
            gc_collect_cycles();
        }
    }

    private function setupDatabaseConnection(Database $database, string $mode): PDO
    {
        $dynamic = app(\Pterodactyl\Extensions\SqlDynDatabaseConnection::class);
        $dynamic->setWithDatabaseCredentials('dynamic', $database);
        $connection = DB::connection('dynamic')->getPdo();
        
        $connection->exec("SET SESSION sql_mode = ''");
        $connection->exec("SET FOREIGN_KEY_CHECKS = 0");
        $connection->exec("SET UNIQUE_CHECKS = 0");
        $connection->exec("SET AUTOCOMMIT = 0");
        $connection->exec("SET SESSION wait_timeout = 300");
        $connection->exec("SET SESSION interactive_timeout = 300");
        $connection->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        if ($mode === 'wipe') {
            $this->wipeDatabaseTables($database, $connection);
        }
        
        $connection->exec("START TRANSACTION");
        
        return $connection;
    }

    private function processFileWithStatementReconstruction(string $filePath, PDO $connection, bool $force): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new DatabaseImportException('Cannot open SQL file for reading');
        }

        $currentStatement = '';
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $executedStatements = 0;
        $skippedStatements = 0;
        $lineNumber = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $trimmedLine = trim($line);
                
                if (empty($trimmedLine)) {
                    continue;
                }
                
                if (!$inString && (str_starts_with($trimmedLine, '--') || str_starts_with($trimmedLine, '#'))) {
                    continue;
                }
                
                if (!$inString && str_starts_with($trimmedLine, '/*')) {
                    $inComment = true;
                    if (strpos($trimmedLine, '*/') !== false) {
                        $inComment = false;
                    }
                    continue;
                }
                
                if ($inComment) {
                    if (strpos($trimmedLine, '*/') !== false) {
                        $inComment = false;
                    }
                    continue;
                }
                
                $currentStatement .= $line;
                
                if ($this->isStatementComplete($line, $inString, $stringChar)) {
                    $statement = trim($currentStatement);
                    
                    if (!empty($statement) && $statement !== ';') {
                        $executeResult = $this->executeStatement($connection, $statement, $force, $lineNumber);
                        
                        if ($executeResult) {
                            $executedStatements++;
                        } else {
                            $skippedStatements++;
                        }
                        
                        if (($executedStatements + $skippedStatements) % self::COMMIT_INTERVAL === 0) {
                            $connection->exec("COMMIT");
                            $connection->exec("START TRANSACTION");
                            gc_collect_cycles();
                            error_log("SIMPLE IMPORT: Processed " . ($executedStatements + $skippedStatements) . " statements at line {$lineNumber}");
                        }
                    }
                    
                    $currentStatement = '';
                }
                
                if (strlen($currentStatement) > self::MAX_STATEMENT_SIZE) {
                    error_log("SIMPLE IMPORT: Statement too large at line {$lineNumber}, skipping");
                    $currentStatement = '';
                    $skippedStatements++;
                }
            }
            
            if (!empty(trim($currentStatement))) {
                $statement = trim($currentStatement);
                if (!empty($statement) && $statement !== ';') {
                    $executeResult = $this->executeStatement($connection, $statement, $force, $lineNumber);
                    if ($executeResult) {
                        $executedStatements++;
                    } else {
                        $skippedStatements++;
                    }
                }
            }
            
        } finally {
            fclose($handle);
        }

        return [
            'executed' => $executedStatements,
            'skipped' => $skippedStatements,
            'lines_processed' => $lineNumber
        ];
    }

    private function isStatementComplete(string $line, bool &$inString, string &$stringChar): bool
    {
        for ($i = 0; $i < strlen($line); $i++) {
            $char = $line[$i];
            
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar) {
                if ($i === 0 || $line[$i-1] !== '\\') {
                    $inString = false;
                    $stringChar = '';
                }
            }
            
            if (!$inString && $char === ';') {
                return true;
            }
        }
        
        return false;
    }

    private function executeStatement(PDO $connection, string $sql, bool $force, int $lineNumber): bool
    {
        try {
            $sql = $this->applyCompatibilityFixes($sql);
            
            if (!$this->isValidStatement($sql)) {
                error_log("INVALID STATEMENT at line {$lineNumber}: " . substr($sql, 0, 200));
                return false;
            }
            
            $upperSql = strtoupper(trim($sql));
            if (str_starts_with($upperSql, 'CREATE TABLE')) {
                error_log("EXECUTING CREATE TABLE at line {$lineNumber}: " . substr($sql, 0, 100));
            } elseif (str_starts_with($upperSql, 'INSERT INTO')) {
                error_log("EXECUTING INSERT at line {$lineNumber}: " . substr($sql, 0, 100));
            }
            
            $connection->exec($sql);
            
            return true;
            
        } catch (\PDOException $e) {
            error_log("STATEMENT ERROR at line {$lineNumber}: " . $e->getMessage());
            error_log("PROBLEMATIC SQL: " . substr($sql, 0, 300));
            
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                error_log("TABLE NOT FOUND ERROR - This might indicate statement ordering issues or missing CREATE TABLE statements");
            }
            
            $errorMessage = $e->getMessage();
            
            if (strpos($errorMessage, "doesn't exist") !== false) {
                error_log("SKIPPING STATEMENT - Referenced object doesn't exist: " . substr($sql, 0, 100));
                return false;
            }
            
            if (strpos($errorMessage, "already exists") !== false || strpos($errorMessage, "Duplicate") !== false) {
                error_log("SKIPPING STATEMENT - Object already exists: " . substr($sql, 0, 100));
                return false;
            }
            
            if (strpos($errorMessage, "syntax error") !== false) {
                error_log("SKIPPING STATEMENT - Syntax error: " . substr($sql, 0, 100));
                return false;
            }
            
            if ($force) {
                error_log("FORCE MODE - Continuing despite error: " . $errorMessage);
                return false;
            }
            
            if (strpos($errorMessage, "Access denied") !== false || 
                strpos($errorMessage, "Connection") !== false ||
                strpos($errorMessage, "Lost connection") !== false) {
                throw $e;
            }
            
            error_log("SKIPPING STATEMENT - Non-critical error: " . $errorMessage);
            return false;
        }
    }

    private function isValidStatement(string $sql): bool
    {
        $sql = trim($sql);
        
        if (empty($sql) || $sql === ';') {
            return false;
        }
        
        $sql = rtrim($sql, ';');
        
        $validStarts = [
            'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 
            'SELECT', 'SET', 'USE', 'SHOW', 'DESCRIBE', 'EXPLAIN',
            'COMMIT', 'ROLLBACK', 'START', 'BEGIN', 'GRANT', 'REVOKE'
        ];
        
        $upperStatement = strtoupper($sql);
        
        foreach ($validStarts as $keyword) {
            if (str_starts_with($upperStatement, $keyword)) {
                return true;
            }
        }
        
        return false;
    }

    private function applyCompatibilityFixes(string $sql): string
    {
        $sql = preg_replace('/\/\*!\d+\s+([^*]+)\s+\*\//', '$1', $sql);
        
        $fixes = [
            'utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_ci',
            "'0000-00-00 00:00:00'" => 'NULL',
            "'0000-00-00'" => 'NULL',
        ];
        
        foreach ($fixes as $search => $replace) {
            $sql = str_replace($search, $replace, $sql);
        }
        
        $sql = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s+/', '', $sql);
        
        $sql = preg_replace('/\s+/', ' ', $sql);
        $sql = trim($sql);
        
        return $sql;
    }

    private function wipeDatabaseTables(Database $database, PDO $connection): void
    {
        try {
            $stmt = $connection->prepare("SHOW TABLES");
            $stmt->execute();
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                $connection->exec("DROP TABLE IF EXISTS `{$table}`");
            }
            
        } catch (\PDOException $e) {
            error_log("WIPE ERROR: " . $e->getMessage());
        }
    }

    private function restoreConnectionSettings(PDO $connection): void
    {
        try {
            $connection->exec("SET FOREIGN_KEY_CHECKS = 1");
            $connection->exec("SET UNIQUE_CHECKS = 1");
            $connection->exec("SET AUTOCOMMIT = 1");
        } catch (\PDOException $e) {
            error_log("RESTORE SETTINGS ERROR: " . $e->getMessage());
        }
    }
}