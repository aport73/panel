<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Management;

use Exception;
use PDO;
use Pterodactyl\Models\Database;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Exceptions\Service\Database\DatabaseExportException;

trait RawSqlExecutor
{
    public function executeRawSQL(Database $database, string $sql, bool $isSelect = true): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            $sql = trim($sql);
            if (empty($sql)) {
                throw new Exception('SQL query cannot be empty');
            }
            
            $sql = rtrim($sql, ';');
            $authorizedDb = $database->database;
            $authorizedDbEscaped = preg_quote($authorizedDb, '/');
            $normalizedSql = preg_replace('/\/\*.*?\*\//s', '', $sql); 
            $normalizedSql = preg_replace('/--.*$/m', '', $normalizedSql); 
            $normalizedSql = preg_replace('/\s+/', ' ', $normalizedSql);
            $normalizedSql = trim($normalizedSql);
            $sqlUpper = strtoupper($normalizedSql);

            if (preg_match('/^SHOW\s+DATABASES$/i', $sql)) {
                $results = [['Database' => $database->database]];
                $columns = ['Database'];
                $rowCount = 1;
                $affectedRows = 0;
                
                $executionTime = 0.1; 
                $timestamp = date('Y-m-d H:i:s');
                
                $terminalOutput = [];
                $terminalOutput[] = "mysql> {$sql};";
                $terminalOutput[] = "1 row in set (0.1 ms)";
                $terminalOutput[] = "";
                
                return [
                    'success' => true,
                    'results' => $results,
                    'columns' => $columns,
                    'row_count' => $rowCount,
                    'affected_rows' => $affectedRows,
                    'execution_time_ms' => $executionTime,
                    'last_insert_id' => null,
                    'query' => $sql,
                    'terminal_output' => implode("\n", $terminalOutput),
                    'executed_at' => $timestamp,
                ];
            }

            $dangerousPatterns = [
                '/(?:^|\s+)DROP\s+DATABASE\s+/i',
                '/(?:^|\s+)CREATE\s+DATABASE\s+/i',
                '/(?:^|\s+)ALTER\s+DATABASE\s+/i',
                '/(?:^|\s+)RENAME\s+DATABASE\s+/i',
                '/(?:^|\s+)CREATE\s+USER\s+/i',
                '/(?:^|\s+)DROP\s+USER\s+/i',
                '/(?:^|\s+)ALTER\s+USER\s+/i',
                '/(?:^|\s+)RENAME\s+USER\s+/i',
                '/(?:^|\s+)GRANT\s+(?!SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|INDEX)/i',
                '/(?:^|\s+)REVOKE\s+/i',
                '/(?:^|\s+)SET\s+PASSWORD\s+/i',
                '/(?:^|\s+)FLUSH\s+PRIVILEGES\s*/i',
                '/(?:^|\s+)LOAD_FILE\s*\(/i',
                '/(?:^|\s+)INTO\s+(?:OUT|DUMP)FILE\s+/i',
                '/(?:^|\s+)LOAD\s+DATA\s+(?:LOCAL\s+)?INFILE\s+/i',
                '/SELECT\s+.*\s+INTO\s+(?:OUT|DUMP)FILE\s+/i',
                '/(?:^|\s+)SHOW\s+GRANTS\s*/i',
                '/(?:^|\s+)SHOW\s+(?:GLOBAL\s+|SESSION\s+)?VARIABLES\s*/i',
                '/(?:^|\s+)SHOW\s+(?:GLOBAL\s+|SESSION\s+)?STATUS\s*/i',
                '/(?:^|\s+)SHOW\s+(?:FULL\s+)?PROCESSLIST\s*/i',
                '/(?:^|\s+)SHOW\s+USERS\s*/i',
                '/(?:^|\s+)SHOW\s+PRIVILEGES\s*/i',
                '/(?:^|\s+)SHOW\s+ENGINES\s*/i',
                '/(?:^|\s+)SHOW\s+PLUGINS\s*/i',
                '/(?:^|\s+)SHOW\s+PROFILES?\s*/i',
                '/(?:^|\s+)SHOW\s+(?:MASTER|SLAVE)\s+STATUS\s*/i',
                '/(?:^|\s+)SHOW\s+(?:BINARY|MASTER)\s+LOGS\s*/i',
                '/(?:^|\s+)CREATE\s+(?:OR\s+REPLACE\s+)?TRIGGER\s+/i',
                '/(?:^|\s+)DROP\s+TRIGGER\s+/i',
                '/(?:^|\s+)CREATE\s+(?:OR\s+REPLACE\s+)?PROCEDURE\s+/i',
                '/(?:^|\s+)DROP\s+PROCEDURE\s+/i',
                '/(?:^|\s+)ALTER\s+PROCEDURE\s+/i',
                '/(?:^|\s+)CREATE\s+(?:OR\s+REPLACE\s+)?FUNCTION\s+/i',
                '/(?:^|\s+)DROP\s+FUNCTION\s+/i',
                '/(?:^|\s+)ALTER\s+FUNCTION\s+/i',
                '/(?:^|\s+)CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+/i',
                '/(?:^|\s+)DROP\s+VIEW\s+/i',
                '/(?:^|\s+)ALTER\s+VIEW\s+/i',
                '/(?:^|\s+)CREATE\s+EVENT\s+/i',
                '/(?:^|\s+)DROP\s+EVENT\s+/i',
                '/(?:^|\s+)ALTER\s+EVENT\s+/i',
                '/(?:^|\s+)DELIMITER\s+/i',
                '/(?:^|\s+|\.|\`)mysql(?:\.|`|\s+|$)/i',
                '/(?:^|\s+)FROM\s+(?:`?mysql`?|`?performance_schema`?|`?sys`?)\s*(?:\.|$)/i',
                '/(?:^|\s+)JOIN\s+(?:`?mysql`?|`?performance_schema`?|`?sys`?)\s*(?:\.|$)/i',
                '/(?:^|\s+)UPDATE\s+(?:`?mysql`?|`?performance_schema`?|`?sys`?)\./i',
                '/(?:^|\s+)INSERT\s+INTO\s+(?:`?mysql`?|`?performance_schema`?|`?sys`?)\./i',
                '/(?:^|\s+)DELETE\s+FROM\s+(?:`?mysql`?|`?performance_schema`?|`?sys`?)\./i',
                '/(?:^|\s+)USE\s+(?!`?' . $authorizedDbEscaped . '`?\s*(?:;|$))/i',
                '/(?:^|\s+)(?:DESCRIBE|DESC|EXPLAIN)\s+(?!`?' . $authorizedDbEscaped . '`?\.)\w+\./i',
                '/@@(?:version|hostname|datadir|basedir|user|database)/i',
                '/(?:^|\s+)(?:SYSTEM|EXEC|EXECUTE|SHELL|CMD)\s*\(/i',
                '/(?:^|\s+)BENCHMARK\s*\(/i',
                '/(?:^|\s+)SLEEP\s*\(/i',
                '/(?:^|\s+)(?:GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK)\s*\(/i',
                '/(?:^|\s+)NAME_CONST\s*\(/i',
                '/(?:^|\s+)(?:VERSION|CONNECTION_ID|DATABASE|USER|CURRENT_USER|SESSION_USER|SYSTEM_USER)\s*\(/i',
                '/(?:^|\s+)UNION\s+(?:ALL\s+)?SELECT\s+.*\s+FROM\s+(?!`?' . $authorizedDbEscaped . '`?\.)/i',
                '/\(\s*SELECT\s+.*\s+FROM\s+(?:mysql|information_schema|performance_schema|sys)\./i',
            ];

            foreach ($dangerousPatterns as $pattern) {
                if (preg_match($pattern, $normalizedSql)) {
                    throw new Exception('SQL query contains forbidden operations');
                }
            }

            try {
                if (method_exists($this, 'validateSqlWithParser')) {
                    $this->validateSqlWithParser($normalizedSql, $authorizedDb);
                }
            } catch (Exception $e) {
                logger()->warning('SQL validation failed', [
                    'sql' => substr($sql, 0, 100),
                    'database' => $authorizedDb,
                    'error' => $e->getMessage(),
                    'user_id' => auth()->id() ?? 'unknown',
                    'ip_address' => request()->ip() ?? 'unknown'
                ]);
                
                throw new Exception('Security validation failed: ' . $e->getMessage());
            }

            if (method_exists($this, 'analyzeQueryComplexity')) {
                $complexity = $this->analyzeQueryComplexity($normalizedSql);
                if ($complexity > 100) {
                    logger()->warning('High complexity query blocked', [
                        'complexity_score' => $complexity,
                        'sql' => substr($normalizedSql, 0, 100),
                        'database' => $authorizedDb
                    ]);
                    
                    throw new Exception('Query complexity too high - possible attack detected');
                }
            }

            if (strpos($sqlUpper, 'SELECT') === 0) {
                if (preg_match('/\\bFROM\\s+(mysql|performance_schema|sys)\\b/i', $normalizedSql)) {
                    throw new Exception('Access to system databases is forbidden');
                }

                if (preg_match_all('/\\bFROM\\s+(\\w+)\\./i', $normalizedSql, $matches)) {
                    foreach ($matches[1] as $dbName) {
                        if (strtolower($dbName) !== strtolower($authorizedDb) && strtolower($dbName) !== 'information_schema') {
                            throw new Exception("SELECT denied: Cannot access database '{$dbName}'");
                        }
                    }
                }

                if (preg_match_all('/\\bJOIN\\s+(\\w+)\\./i', $normalizedSql, $matches)) {
                    foreach ($matches[1] as $dbName) {
                        if (strtolower($dbName) !== strtolower($authorizedDb)) {
                            throw new Exception("JOIN denied: Cannot access database '{$dbName}'");
                        }
                    }
                }

                if (preg_match('/\\bUNION\\b.*\\bFROM\\s+(\\w+)\\./i', $normalizedSql, $matches)) {
                    $dbName = $matches[1];
                    if (strtolower($dbName) !== strtolower($authorizedDb)) {
                        throw new Exception("UNION denied: Cannot access database '{$dbName}'");
                    }
                }
            }
            elseif (strpos($sqlUpper, 'UPDATE') === 0) {
                if (preg_match('/\\bUPDATE\\s+(\\w+)\\./i', $normalizedSql, $matches)) {
                    $dbName = $matches[1];
                    if (strtolower($dbName) !== strtolower($authorizedDb)) {
                        throw new Exception("UPDATE denied: Cannot access database '{$dbName}'");
                    }
                }
            }
            elseif (strpos($sqlUpper, 'DELETE') === 0) {
                if (preg_match('/\\bDELETE\\s+FROM\\s+(\\w+)\\./i', $normalizedSql, $matches)) {
                    $dbName = $matches[1];
                    if (strtolower($dbName) !== strtolower($authorizedDb)) {
                        throw new Exception("DELETE denied: Cannot access database '{$dbName}'");
                    }
                }
            }
            elseif (strpos($sqlUpper, 'INSERT') === 0) {
                if (preg_match('/\\bINSERT\\s+INTO\\s+(\\w+)\\./i', $normalizedSql, $matches)) {
                    $dbName = $matches[1];
                    if (strtolower($dbName) !== strtolower($authorizedDb)) {
                        throw new Exception("INSERT denied: Cannot access database '{$dbName}'");
                    }
                }
            }
            elseif (strpos($sqlUpper, 'SHOW') === 0) {
                $dangerousShowPatterns = [
                    '/SHOW\\s+GRANTS/i',
                    '/SHOW\\s+VARIABLES/i',
                    '/SHOW\\s+STATUS/i',
                    '/SHOW\\s+PROCESSLIST/i',
                    '/SHOW\\s+USERS/i',
                    '/SHOW\\s+PRIVILEGES/i',
                    '/SHOW\\s+ENGINES/i',
                    '/SHOW\\s+PLUGINS/i',
                    '/SHOW\\s+PROFILES/i',
                    '/SHOW\\s+MASTER\\s+STATUS/i',
                    '/SHOW\\s+SLAVE\\s+STATUS/i',
                ];
                
                foreach ($dangerousShowPatterns as $pattern) {
                    if (preg_match($pattern, $normalizedSql)) {
                        throw new Exception('This SHOW command is not allowed');
                    }
                }

                if (preg_match('/\\bFROM\\s+(\\w+)\\./i', $normalizedSql, $matches)) {
                    $dbName = $matches[1];
                    if (strtolower($dbName) !== strtolower($authorizedDb)) {
                        throw new Exception("SHOW denied: Cannot access database '{$dbName}'");
                    }
                }
            }

            if (preg_match('/^(DESCRIBE|DESC)\\s+/i', $sql)) {
                if (!preg_match('/^(DESCRIBE|DESC)\\s+(`?' . preg_quote($authorizedDb, '/') . '`?\\.)?\\w+$/i', $sql)) {
                    throw new Exception('DESCRIBE commands must reference tables in the current database only');
                }
            }

            $additionalBlocks = [
                '/\bFROM\s+(?!information_schema\.)(?!`?information_schema`?\.)([\w`]+)\.(?!' . preg_quote($authorizedDb, '/') . ')/i',
                '/\bJOIN\s+(?!information_schema\.)(?!`?information_schema`?\.)([\w`]+)\.(?!' . preg_quote($authorizedDb, '/') . ')/i',
                '/\bINTO\s+(?!information_schema\.)(?!`?information_schema`?\.)([\w`]+)\.(?!' . preg_quote($authorizedDb, '/') . ')/i',
            ];
            
            foreach ($additionalBlocks as $pattern) {
                if (preg_match($pattern, $sql)) {
                    throw new Exception('Cross-database operations are not allowed');
                }
            }
            
            $startTime = microtime(true);
            $timestamp = date('Y-m-d H:i:s');
            $terminalOutput = [];
            $terminalOutput[] = "mysql> {$sql};";
            $results = [];
            $columns = [];
            $rowCount = 0;
            $affectedRows = 0;
            $lastInsertId = null;
            
            if ($isSelect || stripos($sql, 'SELECT') === 0 || stripos($sql, 'SHOW') === 0 || stripos($sql, 'DESCRIBE') === 0 || stripos($sql, 'EXPLAIN') === 0) {
                $stmt = $connection->prepare($sql);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $rowCount = count($results);
                $affectedRows = 0;
                $columns = [];
                if ($rowCount > 0) {
                    $columns = array_keys($results[0]);
                }
                
            } else {
                $stmt = $connection->prepare($sql);
                $result = $stmt->execute();
                $affectedRows = $stmt->rowCount();
                $results = [];
                $columns = [];
                $rowCount = 0;

                if (stripos($sql, 'INSERT') === 0) {
                    $lastInsertId = $connection->lastInsertId();
                }
            }
            
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($isSelect || stripos($sql, 'SELECT') === 0 || stripos($sql, 'SHOW') === 0 || stripos($sql, 'DESCRIBE') === 0 || stripos($sql, 'EXPLAIN') === 0) {
                if ($rowCount > 0) {
                    $terminalOutput[] = "{$rowCount} row" . ($rowCount !== 1 ? 's' : '') . " in set ({$executionTime} ms)";
                } else {
                    $terminalOutput[] = "Empty set ({$executionTime} ms)";
                }
            } else {
                if (stripos($sql, 'INSERT') === 0) {
                    $terminalOutput[] = "Query OK, {$affectedRows} row" . ($affectedRows !== 1 ? 's' : '') . " affected ({$executionTime} ms)";
                    if (isset($lastInsertId) && $lastInsertId > 0) {
                        $terminalOutput[] = "Last insert ID: {$lastInsertId}";
                    }
                } elseif (stripos($sql, 'UPDATE') === 0) {
                    $terminalOutput[] = "Query OK, {$affectedRows} row" . ($affectedRows !== 1 ? 's' : '') . " affected ({$executionTime} ms)";
                    if ($affectedRows > 0) {
                        $terminalOutput[] = "Rows matched: {$affectedRows}  Changed: {$affectedRows}  Warnings: 0";
                    }
                } elseif (stripos($sql, 'DELETE') === 0) {
                    $terminalOutput[] = "Query OK, {$affectedRows} row" . ($affectedRows !== 1 ? 's' : '') . " affected ({$executionTime} ms)";
                } else {
                    $terminalOutput[] = "Query OK, {$affectedRows} row" . ($affectedRows !== 1 ? 's' : '') . " affected ({$executionTime} ms)";
                }
            }
            
            $terminalOutput[] = "";
            
            return [
                'success' => true,
                'results' => $results,
                'columns' => $columns,
                'row_count' => $rowCount,
                'affected_rows' => $affectedRows,
                'execution_time_ms' => $executionTime,
                'last_insert_id' => $lastInsertId ?? null,
                'query' => $sql,
                'terminal_output' => implode("\n", $terminalOutput),
                'executed_at' => $timestamp,
            ];
            
        } catch (Exception $e) {
            $timestamp = date('Y-m-d H:i:s');
            $terminalOutput = [];
            $terminalOutput[] = "mysql> {$sql};";
            $terminalOutput[] = "ERROR: {$e->getMessage()}";
            $terminalOutput[] = "";
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'query' => $sql ?? '',
                'results' => [],
                'columns' => [],
                'row_count' => 0,
                'affected_rows' => 0,
                'execution_time_ms' => 0,
                'last_insert_id' => null,
                'terminal_output' => implode("\n", $terminalOutput),
                'executed_at' => $timestamp,
            ];
        }
    }
}