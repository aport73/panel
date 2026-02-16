<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Management;

use Exception;
use PDO;
use Pterodactyl\Models\Database;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Exceptions\Service\Database\DatabaseExportException;

trait DatabaseSearcher
{
    public function searchDatabase(Database $database, string $searchTerm, array $tables = [], int $limit = 100): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $results = [];
            $searchTerm = trim($searchTerm);
            
            if (empty($searchTerm)) {
                return [
                    'success' => true,
                    'results' => [],
                    'total_matches' => 0,
                ];
            }

            if (empty($tables)) {
                $tablesStmt = $connection->query("SHOW TABLES FROM `{$database->database}`");
                $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
            }
            
            $totalMatches = 0;
            
            foreach ($tables as $tableName) {
                $columnsStmt = $connection->query("
                    SELECT column_name, data_type
                    FROM information_schema.columns 
                    WHERE table_schema = '{$database->database}' 
                    AND table_name = '{$tableName}'
                    ORDER BY ordinal_position
                ");
                $columns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($columns)) continue;

                $searchConditions = [];
                $textColumns = ['varchar', 'text', 'char', 'longtext', 'mediumtext', 'tinytext'];
                
                foreach ($columns as $column) {
                    $dataType = strtolower($column['data_type']);
                    if (in_array($dataType, $textColumns) || strpos($dataType, 'char') !== false || strpos($dataType, 'text') !== false) {
                        $searchConditions[] = "`{$column['column_name']}` LIKE :search_term";
                    }
                }
                
                if (empty($searchConditions)) continue;

                $searchQuery = "SELECT * FROM `{$database->database}`.`{$tableName}` 
                               WHERE " . implode(' OR ', $searchConditions) . " 
                               LIMIT " . min($limit, 50);
                
                $stmt = $connection->prepare($searchQuery);
                $stmt->execute([':search_term' => "%{$searchTerm}%"]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    $results[] = [
                        'table' => $tableName,
                        'matches' => count($rows),
                        'rows' => $rows,
                        'columns' => array_column($columns, 'column_name'),
                    ];
                    $totalMatches += count($rows);
                }

                if ($totalMatches >= $limit) {
                    break;
                }
            }
            
            return [
                'success' => true,
                'results' => $results,
                'total_matches' => $totalMatches,
                'search_term' => $searchTerm,
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function searchTableData(Database $database, string $tableName, string $searchTerm = '', array $searchColumns = [], int $page = 1, int $limit = 50): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();

            $tableExists = $connection->query("
                SELECT COUNT(*) FROM information_schema.tables 
                WHERE table_schema = '{$database->database}' AND table_name = '{$tableName}'
            ")->fetchColumn();
            
            if (!$tableExists) {
                throw new Exception('Table does not exist');
            }

            $primaryKeys = $connection->query("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_schema = '{$database->database}' 
                AND table_name = '{$tableName}' 
                AND column_key = 'PRI'
                ORDER BY ordinal_position
            ")->fetchAll(PDO::FETCH_COLUMN);

            $whereClause = '';
            $params = [];
            
            if (!empty(trim($searchTerm))) {
                $searchConditions = [];
                
                if (empty($searchColumns)) {
                    $columnsStmt = $connection->query("
                        SELECT column_name, data_type
                        FROM information_schema.columns 
                        WHERE table_schema = '{$database->database}' 
                        AND table_name = '{$tableName}'
                        ORDER BY ordinal_position
                    ");
                    $allColumns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $textColumns = ['varchar', 'text', 'char', 'longtext', 'mediumtext', 'tinytext'];
                    foreach ($allColumns as $column) {
                        $dataType = strtolower($column['data_type']);
                        if (in_array($dataType, $textColumns) || strpos($dataType, 'char') !== false || strpos($dataType, 'text') !== false) {
                            $searchConditions[] = "`{$column['column_name']}` LIKE :search_term";
                        }
                    }
                } else {

                    foreach ($searchColumns as $column) {
                        $searchConditions[] = "`{$column}` LIKE :search_term";
                    }
                }
                
                if (!empty($searchConditions)) {
                    $whereClause = 'WHERE ' . implode(' OR ', $searchConditions);
                    $params[':search_term'] = "%{$searchTerm}%";
                }
            }

            $countSQL = "SELECT COUNT(*) FROM `{$database->database}`.`{$tableName}` {$whereClause}";
            $countStmt = $connection->prepare($countSQL);
            $countStmt->execute($params);
            $totalCount = $countStmt->fetchColumn();
            $offset = ($page - 1) * $limit;
            $dataSQL = "SELECT * FROM `{$database->database}`.`{$tableName}` {$whereClause} LIMIT {$limit} OFFSET {$offset}";
            $dataStmt = $connection->prepare($dataSQL);
            $dataStmt->execute($params);
            $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'data' => $data,
                'total' => (int) $totalCount,
                'page' => $page,
                'limit' => $limit,
                'primary_keys' => $primaryKeys,
                'has_primary_key' => !empty($primaryKeys),
                'search_term' => $searchTerm,
                'filtered' => !empty(trim($searchTerm)),
            ];
            
        } catch (Exception $e) {
            return [
                'data' => [],
                'total' => 0,
                'page' => $page,
                'limit' => $limit,
                'primary_keys' => [],
                'has_primary_key' => false,
                'search_term' => $searchTerm,
                'filtered' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}