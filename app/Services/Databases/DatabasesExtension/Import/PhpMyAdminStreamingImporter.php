<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Import;

use Exception;
use PDO;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Database;
use Pterodactyl\Exceptions\Services\Database\DatabaseImportException;

class PhpMyAdminStreamingImporter
{
    private const CHUNK_SIZE = 8192;
    private const STATEMENT_BUFFER_LIMIT = 32768;
    private const COMMIT_INTERVAL = 50;
    private const GC_INTERVAL = 25;

    public function import(Database $database, string $filePath, string $mode = 'merge', bool $force = false): bool
    {
        $originalMemoryLimit = ini_get('memory_limit');
        
        try {
            ini_set('memory_limit', '32M');
            
            $connection = $this->setupDatabaseConnection($database, $mode);
            
            $stats = $this->processFileInChunks($filePath, $connection, $force);
            
            $connection->exec("COMMIT");
            $this->restoreConnectionSettings($connection);
            
            error_log("STREAMING IMPORT COMPLETED: {$stats['executed']} statements executed, {$stats['skipped']} skipped");
            
            return true;
            
        } catch (Exception $e) {
            error_log("STREAMING IMPORT ERROR: " . $e->getMessage());
            
            try {
                if (isset($connection)) {
                    $connection->exec("ROLLBACK");
                    $this->restoreConnectionSettings($connection);
                }
            } catch (Exception $cleanupError) {
                error_log("CLEANUP ERROR: " . $cleanupError->getMessage());
            }
            
            throw new DatabaseImportException('Streaming import failed: ' . $e->getMessage());
            
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

    private function processFileInChunks(string $filePath, PDO $connection, bool $force): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new DatabaseImportException('Cannot open SQL file for reading');
        }

        $buffer = '';
        $statementBuffer = '';
        $inString = false;
        $stringChar = '';
        $inComment = false;
        $commentType = '';
        $executedStatements = 0;
        $skippedStatements = 0;
        $bytesProcessed = 0;

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                if ($chunk === false) break;
                
                $buffer .= $chunk;
                $bytesProcessed += strlen($chunk);
                
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    
                    $result = $this->processLine($line, $statementBuffer, $inString, $stringChar, $inComment, $commentType);
                    
                    if ($result['statement_complete']) {
                        $executeResult = $this->executeStatement($connection, $result['statement'], $force);
                        
                        if ($executeResult) {
                            $executedStatements++;
                        } else {
                            $skippedStatements++;
                        }
                        
                        $statementBuffer = '';
                        
                        if (($executedStatements + $skippedStatements) % self::COMMIT_INTERVAL === 0) {
                            $connection->exec("COMMIT");
                            $connection->exec("START TRANSACTION");
                        }
                        
                        if (($executedStatements + $skippedStatements) % self::GC_INTERVAL === 0) {
                            gc_collect_cycles();
                            error_log("STREAMING: Processed " . ($executedStatements + $skippedStatements) . " statements, {$bytesProcessed} bytes");
                        }
                    }
                    
                    if (strlen($statementBuffer) > self::STATEMENT_BUFFER_LIMIT) {
                        error_log("STREAMING: Statement too large, skipping");
                        $statementBuffer = '';
                        $skippedStatements++;
                    }
                }
                
                unset($chunk, $line);
            }
            
            if (!empty(trim($statementBuffer))) {
                $executeResult = $this->executeStatement($connection, trim($statementBuffer), $force);
                if ($executeResult) {
                    $executedStatements++;
                } else {
                    $skippedStatements++;
                }
            }
            
        } finally {
            fclose($handle);
        }

        return [
            'executed' => $executedStatements,
            'skipped' => $skippedStatements,
            'bytes_processed' => $bytesProcessed
        ];
    }

    private function processLine(string $line, string &$statementBuffer, bool &$inString, string &$stringChar, bool &$inComment, string &$commentType): array
    {
        $trimmedLine = trim($line);
        
        if (empty($trimmedLine)) {
            return ['statement_complete' => false, 'statement' => ''];
        }
        
        if (!$inString && !$inComment) {
            if (str_starts_with($trimmedLine, '--') || str_starts_with($trimmedLine, '#')) {
                return ['statement_complete' => false, 'statement' => ''];
            }
            if (str_starts_with($trimmedLine, '/*')) {
                $inComment = true;
                $commentType = '/*';
                if (strpos($trimmedLine, '*/') !== false) {
                    $inComment = false;
                }
                return ['statement_complete' => false, 'statement' => ''];
            }
        }
        
        if ($inComment && $commentType === '/*' && strpos($line, '*/') !== false) {
            $inComment = false;
            return ['statement_complete' => false, 'statement' => ''];
        }
        
        if ($inComment) {
            return ['statement_complete' => false, 'statement' => ''];
        }
        
        $statementBuffer .= $line . "\n";
        
        $statementComplete = false;
        
        for ($i = 0; $i < strlen($line); $i++) {
            $char = $line[$i];
            
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar) {
                if ($i === 0 || $line[$i-1] !== '\\') {
                    $inString = false;
                }
            }
            
            if (!$inString && $char === ';') {
                $statementComplete = true;
                break;
            }
        }
        
        if ($statementComplete) {
            $statement = trim($statementBuffer);
            
            if ($this->isValidCompleteStatement($statement)) {
                return [
                    'statement_complete' => true,
                    'statement' => $statement
                ];
            } else {
                return ['statement_complete' => false, 'statement' => ''];
            }
        }
        
        return ['statement_complete' => false, 'statement' => ''];
    }

    private function isValidCompleteStatement(string $statement): bool
    {
        $statement = trim($statement);
        
        if (empty($statement) || $statement === ';') {
            return false;
        }
        
        $validStarts = [
            'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 
            'SELECT', 'SET', 'USE', 'SHOW', 'DESCRIBE', 'EXPLAIN',
            'COMMIT', 'ROLLBACK', 'START', 'BEGIN', 'GRANT', 'REVOKE'
        ];
        
        $upperStatement = strtoupper($statement);
        $startsWithValidKeyword = false;
        
        foreach ($validStarts as $keyword) {
            if (str_starts_with($upperStatement, $keyword)) {
                $startsWithValidKeyword = true;
                break;
            }
        }
        
        if (!$startsWithValidKeyword) {
            error_log("INVALID STATEMENT START: " . substr($statement, 0, 100));
            return false;
        }
        
        if (str_starts_with($upperStatement, 'INSERT')) {
            if (strpos($upperStatement, 'VALUES') === false && strpos($upperStatement, 'SELECT') === false) {
                error_log("INCOMPLETE INSERT: Missing VALUES or SELECT");
                return false;
            }
            
            if (strpos($upperStatement, 'VALUES') !== false) {
                $openParens = substr_count($statement, '(');
                $closeParens = substr_count($statement, ')');
                
                if ($openParens !== $closeParens) {
                    error_log("UNBALANCED PARENS: Open={$openParens}, Close={$closeParens}");
                    return false;
                }
            }
        }
        
        return true;
    }

    private function executeStatement(PDO $connection, string $sql, bool $force): bool
    {
        $sql = trim($sql);
        
        if (empty($sql) || $sql === ';') {
            return false;
        }
        
        try {
            $sql = $this->applyCompatibilityFixes($sql);
            
            $connection->exec($sql);
            
            return true;
            
        } catch (\PDOException $e) {
            error_log("STATEMENT ERROR: " . $e->getMessage());
            error_log("PROBLEMATIC SQL: " . substr($sql, 0, 200));
            
            if ($force) {
                return false;
            }
            
            throw $e;
        }
    }

    private function applyCompatibilityFixes(string $sql): string
    {
        $fixes = [
            'utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_ci',
            '/DEFINER=`[^`]+`@`[^`]+`\s+/' => '',
            "'0000-00-00 00:00:00'" => 'NULL',
            "'0000-00-00'" => 'NULL',
        ];
        
        foreach ($fixes as $search => $replace) {
            if (strpos($search, '/') === 0) {
                $sql = preg_replace($search, $replace, $sql);
            } else {
                $sql = str_replace($search, $replace, $sql);
            }
        }
        
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