<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Import;

use Exception;
use PDO;
use PDOException;
use Pterodactyl\Models\Database;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Exceptions\Service\Database\DatabaseImportException;

trait BatchExecutor
{
    protected function executeBatchWithReconnection(array $statements, Database $database, PDO &$connection): void
    {
        $maxRetries = 3;
        
        foreach ($statements as $statement) {
            if (empty($statement)) continue;
            if (preg_match('/^(SET|START|COMMIT|ROLLBACK|USE)\s+/i', trim($statement))) {
                continue;
            }
            
            $statementRetryCount = 0;
            $statementSuccess = false;
            
            while ($statementRetryCount < $maxRetries && !$statementSuccess) {
                try {
                    if ($statementRetryCount > 0) {
                        $this->checkConnectionHealth($connection);
                    }
                    if (stripos($statement, 'CREATE TABLE') === 0) {
                        preg_match('/CREATE\s+TABLE\s+`?(\w+)`?/i', $statement, $matches);
                        $tableName = $matches[1] ?? 'unknown';
                    }
                    
                    $connection->exec($statement);
                    $statementSuccess = true;
                    if (stripos($statement, 'CREATE TABLE') === 0) {
                        preg_match('/CREATE\s+TABLE\s+`?(\w+)`?/i', $statement, $matches);
                        $tableName = $matches[1] ?? 'unknown';

                    }
                    
                } catch (PDOException $statementError) {
                    if (stripos($statement, 'CREATE TABLE') === 0) {
                        preg_match('/CREATE\s+TABLE\s+`?(\w+)`?/i', $statement, $matches);
                        $tableName = $matches[1] ?? 'unknown';
                        logger()->error('CREATE TABLE statement failed', [
                            'table_name' => $tableName,
                            'error' => $statementError->getMessage(),
                            'error_code' => $statementError->getCode(),
                            'statement_preview' => substr($statement, 0, 300)
                        ]);
                    }

                    if ($statementError->getCode() === '42S02') {
                        $statementSuccess = true; 
                        break;
                    } elseif ($statementError->getCode() === '42000' && 
                             strpos($statementError->getMessage(), 'Query was empty') !== false) {
                        $statementSuccess = true; 
                        break;
                    } elseif ($this->isConnectionError($statementError) && $statementRetryCount < $maxRetries - 1) {
                        $connection = $this->refreshConnection($database);
                        $statementRetryCount++;
                        sleep(1);
                        continue;
                    } else {
                        $statementSuccess = true; 
                        break;
                    }
                }
            }
        }
    }

    protected function executeBatch(array $statements, PDO $connection): void
    {
        foreach ($statements as $statement) {
            if (empty($statement)) continue;
            if (preg_match('/^(SET|START|COMMIT|ROLLBACK|USE)\s+/i', trim($statement))) {
                continue;
            }
            
            try {
                $this->checkConnectionHealth($connection);
                
                $connection->exec($statement);
            } catch (PDOException $e) {
                if ($this->isConnectionError($e)) {
                    logger()->error('MySQL connection lost during import', [
                        'error' => $e->getMessage(),
                        'error_code' => $e->getCode(),
                        'statement_preview' => substr($statement, 0, 100)
                    ]);
                    throw new DatabaseImportException('MySQL connection lost: ' . $e->getMessage());
                }
                
                logger()->warning('SQL import statement failed', [
                    'statement' => substr($statement, 0, 200),
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode()
                ]);
            } catch (Exception $e) {
                logger()->warning('SQL import statement failed', [
                    'statement' => substr($statement, 0, 200),
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode()
                ]);
            }
        }
    }

    protected function isConnectionError(PDOException $e): bool
    {
        $connectionErrorCodes = [
            2006, 
            2013, 
            1053, 
            2002,
            2003, 
        ];
        
        return in_array($e->getCode(), $connectionErrorCodes) || 
               stripos($e->getMessage(), 'server has gone away') !== false ||
               stripos($e->getMessage(), 'lost connection') !== false;
    }

    protected function checkConnectionHealth(PDO $connection): void
    {
        try {
            $connection->query('SELECT 1')->fetchColumn();
        } catch (PDOException $e) {
            if ($this->isConnectionError($e)) {
                throw new DatabaseImportException('Database connection lost during import');
            }
        }
    }

    protected function refreshConnection(Database $database): PDO
    {
        $maxAttempts = 3;
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            try {
                DB::purge('dynamic');
                if ($attempt > 0) {
                    sleep($attempt); 
                }

                $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
                $connection = DB::connection('dynamic')->getPdo();
                $connection->setAttribute(PDO::ATTR_TIMEOUT, 1800);
                $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                try {
                    $connection->exec("SET SESSION wait_timeout=1800");
                    $connection->exec("SET SESSION interactive_timeout=1800");
                    $connection->exec("SET SESSION net_read_timeout=600");
                    $connection->exec("SET SESSION net_write_timeout=600"); 
                } catch (PDOException $e) {
                }

                $connection->query('SELECT 1')->fetchColumn();
                $connection->exec("USE `{$database->database}`");
                $connection->exec("SET FOREIGN_KEY_CHECKS=0");
                $connection->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
                $connection->exec("SET AUTOCOMMIT = 0");
                
                return $connection;
                
            } catch (Exception $e) {
                $attempt++;
                
                if ($attempt >= $maxAttempts) {
                    throw new DatabaseImportException('Failed to refresh database connection after ' . $maxAttempts . ' attempts: ' . $e->getMessage());
                }
            }
        }
    }
}