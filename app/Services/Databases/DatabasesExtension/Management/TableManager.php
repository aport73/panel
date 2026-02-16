<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Management;

use Exception;
use PDO;
use Pterodactyl\Models\Database;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Exceptions\Service\Database\DatabaseExportException;

trait TableManager
{
    private function checkForeignKeyConstraints(Database $database, string $tableName, array $primaryKeyValues, PDO $connection): array
    {
        try {
            $foreignKeyQuery = "
                SELECT 
                    kcu.TABLE_NAME as referencing_table,
                    kcu.COLUMN_NAME as referencing_column,
                    kcu.REFERENCED_COLUMN_NAME as referenced_column,
                    kcu.CONSTRAINT_NAME as constraint_name
                FROM information_schema.KEY_COLUMN_USAGE kcu
                WHERE kcu.REFERENCED_TABLE_SCHEMA = :database_name 
                AND kcu.REFERENCED_TABLE_NAME = :table_name
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ";
            
            $stmt = $connection->prepare($foreignKeyQuery);
            $stmt->execute([
                ':database_name' => $database->database,
                ':table_name' => $tableName
            ]);
            $foreignKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($foreignKeys)) {
                return ['has_constraints' => false, 'references' => []];
            }
            
            $references = [];
            
            foreach ($foreignKeys as $fk) {
                $referencingTable = $fk['referencing_table'];
                $referencingColumn = $fk['referencing_column'];
                $referencedColumn = $fk['referenced_column'];
                
                if (!isset($primaryKeyValues[$referencedColumn])) {
                    continue;
                }
                
                $checkQuery = "
                    SELECT COUNT(*) FROM `{$database->database}`.`{$referencingTable}` 
                    WHERE `{$referencingColumn}` = :referenced_value
                ";
                
                $checkStmt = $connection->prepare($checkQuery);
                $checkStmt->execute([':referenced_value' => $primaryKeyValues[$referencedColumn]]);
                $referenceCount = $checkStmt->fetchColumn();
                
                if ($referenceCount > 0) {
                    $references[] = [
                        'table' => $referencingTable,
                        'column' => $referencingColumn,
                        'constraint' => $fk['constraint_name'],
                        'reference_count' => (int) $referenceCount
                    ];
                }
            }
            
            return [
                'has_constraints' => !empty($references),
                'references' => $references
            ];
            
        } catch (Exception $e) {
            return ['has_constraints' => false, 'references' => [], 'error' => $e->getMessage()];
        }
    }

    private function logForeignKeyViolation(Database $database, string $operation, string $tableName, array $primaryKeyValues, array $references): void
    {
        try {
            \Log::warning('Foreign key constraint violation detected', [
                'database' => $database->database,
                'operation' => $operation,
                'table' => $tableName,
                'primary_keys' => $primaryKeyValues,
                'foreign_key_references' => $references,
                'timestamp' => now()->toISOString()
            ]);
        } catch (Exception $e) {
        }
    }

    private function processValueByDataType($value, array $columnInfo)
    {
        if ($value === null) {
            return null;
        }
        
        $dataType = strtolower($columnInfo['data_type']);
        $maxLength = $columnInfo['character_maximum_length'];
        
        try {
            switch ($dataType) {
                case 'boolean':
                case 'bool':
                case 'tinyint':
                    if (is_string($value) && !empty($value)) {
                        $lowerValue = strtolower(trim($value));
                        if ($lowerValue === 'true') {
                            return '1';
                        } elseif ($lowerValue === 'false') {
                            return '0';
                        }
                    }
                    return $value;
                    
                case 'varchar':
                case 'char':
                    if (is_string($value)) {
                        if (!mb_check_encoding($value, 'UTF-8')) {
                            $value = mb_convert_encoding($value, 'UTF-8', 'auto');
                        }

                        if ($maxLength && mb_strlen($value) > $maxLength) {
                            \Log::warning('Truncating varchar/char value for column', [
                                'column' => $columnInfo['column_name'],
                                'original_length' => mb_strlen($value),
                                'max_length' => $maxLength,
                                'original_value_preview' => mb_substr($value, 0, 50) . '...',
                                'truncated_value_preview' => mb_substr($value, 0, min(50, $maxLength)) . '...'
                            ]);
                            $value = mb_substr($value, 0, $maxLength);
                        }
                    }
                    return $value;
                    
                case 'text':
                case 'mediumtext':
                case 'longtext':
                    if (is_string($value)) {
                        if (!mb_check_encoding($value, 'UTF-8')) {
                            $value = mb_convert_encoding($value, 'UTF-8', 'auto');
                        }

                        $sizeLimit = match($dataType) {
                            'text' => 65535,
                            'mediumtext' => 16777215,
                            'longtext' => 4294967295,
                            default => null
                        };
                        
                        if ($sizeLimit && strlen($value) > $sizeLimit) {
                            \Log::warning('Text content exceeds limit', [
                                'column' => $columnInfo['column_name'],
                                'data_type' => $dataType,
                                'content_size' => strlen($value),
                                'limit' => $sizeLimit
                            ]);
                            $value = substr($value, 0, $sizeLimit);
                        }
                    }
                    return $value;
                    
                case 'json':
                    if (is_string($value)) {
                        $decoded = json_decode($value);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            \Log::warning('Invalid JSON content detected', [
                                'column' => $columnInfo['column_name'],
                                'json_error' => json_last_error_msg(),
                                'content_preview' => substr($value, 0, 100)
                            ]);
                            $value = json_encode($value);
                        }
                    }
                    return $value;
                    
                case 'int':
                case 'integer':
                case 'bigint':
                case 'smallint':
                case 'mediumint':
                case 'tinyint':
                    if (is_string($value) && !is_numeric($value)) {
                        \Log::warning('Non-numeric value for integer column', [
                            'column' => $columnInfo['column_name'],
                            'value' => $value
                        ]);
                        return 0; 
                    }
                    return $value;
                    
                case 'decimal':
                case 'float':
                case 'double':
                    if (is_string($value) && !is_numeric($value)) {
                        \Log::warning('Non-numeric value for decimal column', [
                            'column' => $columnInfo['column_name'],
                            'value' => $value
                        ]);
                        return 0.0; 
                    }
                    return $value;
                    
                case 'date':
                case 'datetime':
                case 'timestamp':
                    if (is_string($value) && !empty($value)) {
                        try {
                            $date = new \DateTime($value);
                            return $date->format($dataType === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
                        } catch (\Exception $e) {
                            \Log::warning('Invalid date format', [
                                'column' => $columnInfo['column_name'],
                                'value' => $value,
                                'error' => $e->getMessage()
                            ]);
                            return null; 
                        }
                    }
                    return $value;
                    
                case 'blob':
                case 'mediumblob':
                case 'longblob':
                case 'binary':
                case 'varbinary':
                    if (is_string($value)) {
                        $sizeLimit = match($dataType) {
                            'blob' => 65535,
                            'mediumblob' => 16777215,
                            'longblob' => 4294967295,
                            default => null
                        };
                        
                        if ($sizeLimit && strlen($value) > $sizeLimit) {
                            \Log::warning('Binary content exceeds limit', [
                                'column' => $columnInfo['column_name'],
                                'data_type' => $dataType,
                                'content_size' => strlen($value),
                                'limit' => $sizeLimit
                            ]);
                            $value = substr($value, 0, $sizeLimit);
                        }
                    }
                    return $value;
                    
                default:
                    return $value;
            }
        } catch (Exception $e) {
            \Log::error('Error processing value by data type', [
                'column' => $columnInfo['column_name'],
                'data_type' => $dataType,
                'error' => $e->getMessage(),
                'value_preview' => is_string($value) ? substr($value, 0, 100) : gettype($value)
            ]);
            return $value; 
        }
    }

    public function deleteTableRow(Database $database, string $tableName, array $primaryKeyValues, bool $force = false): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();

            if ($force) {
                $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
            }

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
            
            if (empty($primaryKeys)) {
                throw new Exception('Cannot delete rows from table without primary key');
            }

            foreach ($primaryKeys as $pkColumn) {
                if (!isset($primaryKeyValues[$pkColumn])) {
                    throw new Exception("Primary key value for {$pkColumn} is required");
                }
            }

            if (!$force) {
                $constraintCheck = $this->checkForeignKeyConstraints($database, $tableName, $primaryKeyValues, $connection);
                if ($constraintCheck['has_constraints']) {
                $this->logForeignKeyViolation($database, 'DELETE', $tableName, $primaryKeyValues, $constraintCheck['references']);
                
                $referencingTables = array_map(function($ref) {
                    return $ref['table'] . ' (' . $ref['reference_count'] . ' references)';
                }, $constraintCheck['references']);
                
                return [
                    'success' => false,
                    'error' => "Cannot delete row because it is referenced by foreign key constraints in tables: " . implode(', ', $referencingTables),
                    'foreign_key_error' => true,
                    'references' => $constraintCheck['references']
                ];
                }
            }
            
            $whereConditions = [];
            $params = [];
            $paramIndex = 1;
            
            foreach ($primaryKeys as $pkColumn) {
                $whereConditions[] = "`{$pkColumn}` = :param{$paramIndex}";
                $params[":param{$paramIndex}"] = $primaryKeyValues[$pkColumn];
                $paramIndex++;
            }
            
            $sql = "DELETE FROM `{$database->database}`.`{$tableName}` 
                    WHERE " . implode(' AND ', $whereConditions) . " 
                    LIMIT 1";
            
            $stmt = $connection->prepare($sql);
            $result = $stmt->execute($params);
            
            if (!$result) {
                throw new Exception('Failed to delete row');
            }
            
            $affectedRows = $stmt->rowCount();
            
            if ($affectedRows === 0) {
                throw new Exception('No rows were deleted. Row may not exist.');
            }

            if ($force) {
                $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            
            return [
                'success' => true,
                'affected_rows' => $affectedRows,
                'message' => 'Row deleted successfully',
            ];
            
        } catch (Exception $e) {
            if ($force) {
                try {
                    $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
                } catch (Exception $restoreException) {
                    \Log::warning('Failed to restore foreign key checks', [
                        'error' => $restoreException->getMessage()
                    ]);
                }
            }

            $errorMessage = $e->getMessage();
            $isForeignKeyConstraint = (
                strpos($errorMessage, 'foreign key constraint fails') !== false ||
                strpos($errorMessage, 'FOREIGN KEY constraint failed') !== false ||
                strpos($errorMessage, 'Cannot add or update a child row') !== false ||
                strpos($errorMessage, 'Cannot delete or update a parent row') !== false
            );
            
            if ($isForeignKeyConstraint) {
                return [
                    'success' => false,
                    'error' => 'Konstantin\'s foreign key constraint issue: ' . $errorMessage,
                    'foreign_key_error' => true,
                    'error_type' => 'foreign_key_constraint'
                ];
            }
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function createTable(Database $database, string $tableName, array $columns): array
    {
        try {

            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();

            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
                \Log::error('Invalid table name format', ['table_name' => $tableName]);
                throw new Exception('Invalid table name. Use only letters, numbers, and underscores.');
            }

            $existsStmt = $connection->query("
                SELECT COUNT(*) FROM information_schema.tables 
                WHERE table_schema = '{$database->database}' AND table_name = '{$tableName}'
            ");
            
            $tableExists = $existsStmt->fetchColumn();
            
            if ($tableExists > 0) {
                \Log::error('Table already exists', ['table_name' => $tableName]);
                throw new Exception('Table already exists');
            }

            $columnsSQL = [];
            $primaryKeys = [];

            foreach ($columns as $index => $column) {
                $columnType = strtoupper($column['type']);
                $columnSQL = "`{$column['name']}` {$columnType}";

                if ($columnType === 'ENUM' && isset($column['enum_values']) && !empty($column['enum_values'])) {
                    $enumValues = array_map(function($value) {
                        return "'" . str_replace("'", "''", $value) . "'";
                    }, $column['enum_values']);
                    $columnSQL .= "(" . implode(',', $enumValues) . ")";
                } elseif ($columnType === 'SET' && isset($column['set_values']) && !empty($column['set_values'])) {
                    $setValues = array_map(function($value) {
                        return "'" . str_replace("'", "''", $value) . "'";
                    }, $column['set_values']);
                    $columnSQL .= "(" . implode(',', $setValues) . ")";
                } elseif (!empty($column['length'])) {
                    if ($columnType === 'DECIMAL' && isset($column['precision'])) {
                        $columnSQL .= "({$column['length']},{$column['precision']})";
                    } else {
                        $columnSQL .= "({$column['length']})";
                    }
                }
                
                if ($column['nullable'] === false) {
                    $columnSQL .= " NOT NULL";
                }

                if (isset($column['auto_increment']) && $column['auto_increment'] === true) {
                } elseif (isset($column['default']) && trim($column['default']) !== '') {
                    $defaultValue = trim($column['default']);
                    $columnType = strtoupper($column['type']);
                    switch ($columnType) {
                        case 'BOOLEAN':
                        case 'BOOL':
                            if (strtolower($defaultValue) === 'true' || $defaultValue === '1') {
                                $columnSQL .= " DEFAULT TRUE";
                            } elseif (strtolower($defaultValue) === 'false' || $defaultValue === '0') {
                                $columnSQL .= " DEFAULT FALSE";
                            } else {
                                $columnSQL .= " DEFAULT FALSE"; 
                            }
                            break;
                            
                        case 'TINYINT':
                        case 'SMALLINT':
                        case 'MEDIUMINT':
                        case 'INT':
                        case 'BIGINT':
                            if (is_numeric($defaultValue)) {
                                $columnSQL .= " DEFAULT {$defaultValue}";
                            }
                            break;
                            
                        case 'BIT':
                            if (in_array($defaultValue, ['0', '1'])) {
                                $columnSQL .= " DEFAULT {$defaultValue}";
                            }
                            break;
                            
                        case 'DECIMAL':
                        case 'FLOAT':
                        case 'DOUBLE':
                            if (is_numeric($defaultValue)) {
                                $columnSQL .= " DEFAULT {$defaultValue}";
                            }
                            break;
                            
                        case 'DATE':
                            if ($defaultValue === 'CURRENT_DATE' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $defaultValue)) {
                                $columnSQL .= " DEFAULT '{$defaultValue}'";
                            }
                            break;
                            
                        case 'DATETIME':
                        case 'TIMESTAMP':
                            if (in_array($defaultValue, ['CURRENT_TIMESTAMP', 'NOW()']) || 
                                preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $defaultValue)) {
                                if (in_array($defaultValue, ['CURRENT_TIMESTAMP', 'NOW()'])) {
                                    $columnSQL .= " DEFAULT {$defaultValue}";
                                } else {
                                    $columnSQL .= " DEFAULT '{$defaultValue}'";
                                }
                            }
                            break;
                            
                        case 'TIME':
                            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $defaultValue)) {
                                $columnSQL .= " DEFAULT '{$defaultValue}'";
                            }
                            break;
                            
                        case 'YEAR':
                            if (is_numeric($defaultValue) && $defaultValue >= 1901 && $defaultValue <= 2155) {
                                $columnSQL .= " DEFAULT {$defaultValue}";
                            }
                            break;
                            
                        case 'JSON':
                            if ($defaultValue === 'NULL' || json_decode($defaultValue) !== null) {
                                if ($defaultValue === 'NULL') {
                                    $columnSQL .= " DEFAULT NULL";
                                } else {
                                    $escapedDefault = str_replace("'", "''", $defaultValue);
                                    $columnSQL .= " DEFAULT '{$escapedDefault}'";
                                }
                            }
                            break;
                            
                        case 'ENUM':
                            if (isset($column['enum_values']) && in_array($defaultValue, $column['enum_values'])) {
                                $escapedDefault = str_replace("'", "''", $defaultValue);
                                $columnSQL .= " DEFAULT '{$escapedDefault}'";
                            }
                            break;
                            
                        case 'SET':
                            if (isset($column['set_values'])) {
                                $setValues = explode(',', $defaultValue);
                                $validValues = array_intersect($setValues, $column['set_values']);
                                if (count($validValues) === count($setValues)) {
                                    $escapedDefault = str_replace("'", "''", $defaultValue);
                                    $columnSQL .= " DEFAULT '{$escapedDefault}'";
                                }
                            }
                            break;
                            
                        default:
                            $escapedDefault = str_replace("'", "''", $defaultValue);
                            $columnSQL .= " DEFAULT '{$escapedDefault}'";
                            break;
                    }
                }
                
                if (isset($column['auto_increment']) && $column['auto_increment'] === true) {
                    $columnSQL .= " AUTO_INCREMENT";
                    if (!in_array($column['name'], $primaryKeys)) {
                        $primaryKeys[] = $column['name'];
                    }
                }
                
                if (isset($column['primary_key']) && $column['primary_key'] === true) {
                    if (!in_array($column['name'], $primaryKeys)) {
                        $primaryKeys[] = $column['name'];
                    }
                }
                
                $columnsSQL[] = $columnSQL;
            }

            if (!empty($primaryKeys)) {
                $columnsSQL[] = "PRIMARY KEY (`" . implode('`, `', $primaryKeys) . "`)";
            }
            
            $sql = "CREATE TABLE `{$database->database}`.`{$tableName}` (\n" . 
                   "  " . implode(",\n  ", $columnsSQL) . "\n)";

            
            try {
                $connection->exec($sql);
            } catch (Exception $sqlException) {
                \Log::error('SQL execution failed', [
                    'sql' => $sql,
                    'error' => $sqlException->getMessage(),
                    'columns_sql' => $columnsSQL,
                    'primary_keys' => $primaryKeys
                ]);
                throw $sqlException;
            }
            
            return [
                'success' => true,
                'message' => 'Table created successfully',
            ];
            
        } catch (Exception $e) {
            \Log::error('TableManager::createTable failed', [
                'database' => $database->database,
                'table_name' => $tableName,
                'columns' => $columns,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function addColumn(Database $database, string $tableName, array $columnData): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();

            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $columnData['name'])) {
                throw new Exception('Invalid column name. Use only letters, numbers, and underscores.');
            }

            $tableExists = $connection->query("
                SELECT COUNT(*) FROM information_schema.tables 
                WHERE table_schema = '{$database->database}' AND table_name = '{$tableName}'
            ")->fetchColumn();
            
            if (!$tableExists) {
                throw new Exception('Table does not exist');
            }

            $columnExists = $connection->query("
                SELECT COUNT(*) FROM information_schema.columns 
                WHERE table_schema = '{$database->database}' 
                AND table_name = '{$tableName}' 
                AND column_name = '{$columnData['name']}'
            ")->fetchColumn();
            
            if ($columnExists) {
                throw new Exception('Column already exists');
            }

            if (isset($columnData['auto_increment']) && $columnData['auto_increment'] === true) {
                $existingAutoIncrement = $connection->query("
                    SELECT COUNT(*) FROM information_schema.columns 
                    WHERE table_schema = '{$database->database}' 
                    AND table_name = '{$tableName}' 
                    AND extra LIKE '%auto_increment%'
                ")->fetchColumn();
                
                if ($existingAutoIncrement > 0) {
                    throw new Exception('Table already has an AUTO_INCREMENT column. Only one AUTO_INCREMENT column is allowed per table.');
                }
            }

            if (isset($columnData['primary_key']) && $columnData['primary_key'] === true) {
                $existingPrimaryKey = $connection->query("
                    SELECT COUNT(*) FROM information_schema.table_constraints 
                    WHERE table_schema = '{$database->database}' 
                    AND table_name = '{$tableName}' 
                    AND constraint_type = 'PRIMARY KEY'
                ")->fetchColumn();
                
                if ($existingPrimaryKey > 0) {
                    throw new Exception('Table already has a primary key. Drop the existing primary key first.');
                }
            }

            $columnSQL = "`{$columnData['name']}` {$columnData['type']}";
            
            if (!empty($columnData['length'])) {
                $columnSQL .= "({$columnData['length']})";
            }
            
            if ($columnData['nullable'] === false) {
                $columnSQL .= " NOT NULL";
            }
            
            if (!empty($columnData['default']) && trim($columnData['default']) !== '') {
                if ($columnData['auto_increment'] === true) {
                } else {
                    $defaultValue = trim($columnData['default']);

                    if (strtoupper($columnData['type']) === 'BOOLEAN') {
                        if (in_array(strtolower($defaultValue), ['true', '1', 'yes'])) {
                            $columnSQL .= " DEFAULT TRUE";
                        } elseif (in_array(strtolower($defaultValue), ['false', '0', 'no'])) {
                            $columnSQL .= " DEFAULT FALSE";
                        } else {
                            throw new Exception('Invalid BOOLEAN default value. Use true/false, 1/0, or yes/no.');
                        }
                    } else {
                        $escapedDefault = str_replace("'", "''", $defaultValue);
                        $columnSQL .= " DEFAULT '{$escapedDefault}'";
                    }
                }
            }
            
            if ($columnData['auto_increment'] === true) {
                $columnSQL .= " AUTO_INCREMENT";
            }
            
            $sql = "ALTER TABLE `{$database->database}`.`{$tableName}` ADD COLUMN {$columnSQL}";
            
            if (!empty($columnData['after'])) {
                $sql .= " AFTER `{$columnData['after']}`";
            }
            
            $connection->exec($sql);

            if ((isset($columnData['primary_key']) && $columnData['primary_key'] === true) || 
                (isset($columnData['auto_increment']) && $columnData['auto_increment'] === true)) {
                $primaryKeySql = "ALTER TABLE `{$database->database}`.`{$tableName}` ADD PRIMARY KEY (`{$columnData['name']}`)";
                $connection->exec($primaryKeySql);
            }
            
            return [
                'success' => true,
                'message' => 'Column added successfully',
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function updateTableRow(Database $database, string $tableName, array $primaryKeyValues, array $updateData, bool $force = false): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();

            if ($force) {
                $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
            }

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
            
            if (empty($primaryKeys)) {
                throw new Exception('Cannot update rows in table without primary key');
            }

            foreach ($primaryKeys as $pkColumn) {
                if (!isset($primaryKeyValues[$pkColumn])) {
                    throw new Exception("Primary key value for {$pkColumn} is required");
                }
            }

            $columns = $connection->query("
                SELECT 
                    column_name, 
                    data_type, 
                    is_nullable, 
                    column_default, 
                    extra,
                    character_maximum_length,
                    character_set_name,
                    collation_name,
                    column_type
                FROM information_schema.columns 
                WHERE table_schema = '{$database->database}' 
                AND table_name = '{$tableName}'
                ORDER BY ordinal_position
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $columnMap = [];
            foreach ($columns as $column) {
                $columnMap[$column['column_name']] = $column;
            }

            $isUpdatingReferencedColumns = false;
            $referencedColumns = [];
            
            foreach ($updateData as $columnName => $value) {
                $fkQuery = "
                    SELECT DISTINCT kcu.TABLE_NAME as referencing_table, kcu.CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE kcu
                    WHERE kcu.REFERENCED_TABLE_SCHEMA = :database_name 
                    AND kcu.REFERENCED_TABLE_NAME = :table_name 
                    AND kcu.REFERENCED_COLUMN_NAME = :column_name
                    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                ";
                
                $fkStmt = $connection->prepare($fkQuery);
                $fkStmt->execute([
                    ':database_name' => $database->database,
                    ':table_name' => $tableName,
                    ':column_name' => $columnName
                ]);
                $referencingTables = $fkStmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($referencingTables)) {
                    $isUpdatingReferencedColumns = true;
                    $referencedColumns[$columnName] = $referencingTables;
                }
            }

            if ($isUpdatingReferencedColumns) {
                $constraintCheck = $this->checkForeignKeyConstraints($database, $tableName, $primaryKeyValues, $connection);
                if ($constraintCheck['has_constraints']) {
                    $this->logForeignKeyViolation($database, 'UPDATE', $tableName, $primaryKeyValues, $constraintCheck['references']);
                    
                    $affectedColumns = array_keys($referencedColumns);
                    return [
                        'success' => false,
                        'error' => "Cannot update columns (" . implode(', ', $affectedColumns) . ") because they are referenced by foreign key constraints",
                        'foreign_key_error' => true,
                        'references' => $constraintCheck['references'],
                        'affected_columns' => $referencedColumns
                    ];
                }
            }

            if (!$force) {

            }

            $validatedData = [];
            foreach ($updateData as $columnName => $value) {
                if (!isset($columnMap[$columnName])) {
                    throw new Exception("Column {$columnName} does not exist");
                }
                
                $columnInfo = $columnMap[$columnName];

                if (strpos($columnInfo['extra'], 'auto_increment') !== false) {
                    throw new Exception("Cannot update auto-increment column {$columnName}");
                }

                if ($value === null && $columnInfo['is_nullable'] === 'NO') {
                    throw new Exception("Column {$columnName} cannot be null");
                }

                $dataType = strtolower($columnInfo['data_type']);
                $processedValue = $this->processValueByDataType($value, $columnInfo);
                
                $validatedData[$columnName] = $processedValue;
            }
            
            if (empty($validatedData)) {
                throw new Exception('No valid data to update');
            }

            $currentRowData = [];
            try {
                $selectWhereConditions = [];
                $selectParams = [];
                $selectParamIndex = 1;
                
                foreach ($primaryKeys as $pkColumn) {
                    $selectWhereConditions[] = "`{$pkColumn}` = :select_param{$selectParamIndex}";
                    $selectParams[":select_param{$selectParamIndex}"] = $primaryKeyValues[$pkColumn];
                    $selectParamIndex++;
                }
                
                $selectSql = "SELECT * FROM `{$database->database}`.`{$tableName}` 
                             WHERE " . implode(' AND ', $selectWhereConditions) . " 
                             LIMIT 1";
                
                $selectStmt = $connection->prepare($selectSql);
                $selectStmt->execute($selectParams);
                $currentRowData = $selectStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {
                \Log::warning('Could not fetch current row data for comparison', [
                    'database' => $database->database,
                    'table' => $tableName,
                    'primary_keys' => $primaryKeyValues,
                    'error' => $e->getMessage()
                ]);
            }
 
            $updateAttemptLog = [
                'database' => $database->database,
                'table' => $tableName,
                'primary_keys' => $primaryKeyValues,
                'attempted_changes' => [],
                'original_data' => $updateData,
                'processed_data' => $validatedData,
                'current_row_data' => $currentRowData
            ];
            
            foreach ($validatedData as $columnName => $newValue) {
                $currentValue = $currentRowData[$columnName] ?? null;
                $updateAttemptLog['attempted_changes'][$columnName] = [
                    'original_input' => $updateData[$columnName] ?? null,
                    'processed_value' => $newValue,
                    'current_db_value' => $currentValue,
                    'data_type' => $columnMap[$columnName]['data_type'] ?? 'unknown',
                    'is_different' => $currentValue !== $newValue,
                    'value_comparison' => [
                        'current_type' => gettype($currentValue),
                        'new_type' => gettype($newValue),
                        'current_length' => is_string($currentValue) ? strlen($currentValue) : null,
                        'new_length' => is_string($newValue) ? strlen($newValue) : null
                    ]
                ];
            }

            $setClause = [];
            $params = [];
            $paramIndex = 1;
            
            foreach ($validatedData as $columnName => $value) {
                $setClause[] = "`{$columnName}` = :update_param{$paramIndex}";
                $params[":update_param{$paramIndex}"] = $value;
                $paramIndex++;
            }
            
            $whereConditions = [];
            foreach ($primaryKeys as $pkColumn) {
                $whereConditions[] = "`{$pkColumn}` = :pk_param{$paramIndex}";
                $params[":pk_param{$paramIndex}"] = $primaryKeyValues[$pkColumn];
                $paramIndex++;
            }
            
            $sql = "UPDATE `{$database->database}`.`{$tableName}` 
                    SET " . implode(', ', $setClause) . " 
                    WHERE " . implode(' AND ', $whereConditions) . " 
                    LIMIT 1";
            
            $stmt = $connection->prepare($sql);
            $result = $stmt->execute($params);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                \Log::error('SQL update execution failed', [
                    'sql' => $sql,
                    'parameters' => $params,
                    'pdo_error' => $errorInfo,
                    'database' => $database->database,
                    'table' => $tableName
                ]);
                throw new Exception('Failed to update row: ' . ($errorInfo[2] ?? 'Unknown SQL error'));
            }
            
            $affectedRows = $stmt->rowCount();

            if ($affectedRows === 0) {
                $checkWhereConditions = [];
                $checkParams = [];
                $checkParamIndex = 1;
                
                foreach ($primaryKeys as $pkColumn) {
                    $checkWhereConditions[] = "`{$pkColumn}` = :check_param{$checkParamIndex}";
                    $checkParams[":check_param{$checkParamIndex}"] = $primaryKeyValues[$pkColumn];
                    $checkParamIndex++;
                }
                
                $checkSql = "SELECT COUNT(*) FROM `{$database->database}`.`{$tableName}` 
                            WHERE " . implode(' AND ', $checkWhereConditions);
                
                $checkStmt = $connection->prepare($checkSql);
                $checkStmt->execute($checkParams);
                $rowExists = $checkStmt->fetchColumn();
                
                if ($rowExists == 0) {
                    \Log::error('Row update failed - row does not exist', [
                        'database' => $database->database,
                        'table' => $tableName,
                        'primary_keys' => $primaryKeyValues,
                        'check_sql' => $checkSql,
                        'check_params' => $checkParams
                    ]);
                    throw new Exception('No rows were updated. Row does not exist.');
                }

                $postUpdateSql = "SELECT * FROM `{$database->database}`.`{$tableName}` 
                                 WHERE " . implode(' AND ', $checkWhereConditions) . " 
                                 LIMIT 1";
                
                $postUpdateStmt = $connection->prepare($postUpdateSql);
                $postUpdateStmt->execute($checkParams);
                $postUpdateData = $postUpdateStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $noChangeAnalysis = [
                    'database' => $database->database,
                    'table' => $tableName,
                    'primary_keys' => $primaryKeyValues,
                    'reason' => 'No rows affected - likely data unchanged',
                    'attempted_changes' => [],
                    'post_update_data' => $postUpdateData
                ];
                
                foreach ($validatedData as $columnName => $newValue) {
                    $actualCurrentValue = $postUpdateData[$columnName] ?? null;
                    $noChangeAnalysis['attempted_changes'][$columnName] = [
                        'attempted_value' => $newValue,
                        'actual_current_value' => $actualCurrentValue,
                        'values_match' => $actualCurrentValue == $newValue,
                        'strict_match' => $actualCurrentValue === $newValue,
                        'type_comparison' => [
                            'attempted_type' => gettype($newValue),
                            'current_type' => gettype($actualCurrentValue)
                        ]
                    ];
                }
                
                \Log::warning('Row update resulted in 0 affected rows', $noChangeAnalysis);

                return [
                    'success' => true,
                    'affected_rows' => 0,
                    'message' => 'Row updated successfully (no changes needed)',
                    'data_unchanged' => true,
                    'analysis' => $noChangeAnalysis
                ];
            }

            $postUpdateWhereConditions = [];
            $postUpdateParams = [];
            $postUpdateParamIndex = 1;
            
            foreach ($primaryKeys as $pkColumn) {
                $postUpdateWhereConditions[] = "`{$pkColumn}` = :post_param{$postUpdateParamIndex}";
                $postUpdateParams[":post_param{$postUpdateParamIndex}"] = $primaryKeyValues[$pkColumn];
                $postUpdateParamIndex++;
            }
            
            $postUpdateSql = "SELECT * FROM `{$database->database}`.`{$tableName}` 
                             WHERE " . implode(' AND ', $postUpdateWhereConditions) . " 
                             LIMIT 1";
            
            $postUpdateStmt = $connection->prepare($postUpdateSql);
            $postUpdateStmt->execute($postUpdateParams);
            $finalRowData = $postUpdateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $successLog = [
                'database' => $database->database,
                'table' => $tableName,
                'primary_keys' => $primaryKeyValues,
                'affected_rows' => $affectedRows,
                'successful_changes' => []
            ];
            
            foreach ($validatedData as $columnName => $attemptedValue) {
                $finalValue = $finalRowData[$columnName] ?? null;
                $originalValue = $currentRowData[$columnName] ?? null;
                
                $successLog['successful_changes'][$columnName] = [
                    'original_value' => $originalValue,
                    'attempted_value' => $attemptedValue,
                    'final_value' => $finalValue,
                    'change_successful' => $finalValue == $attemptedValue,
                    'value_actually_changed' => $originalValue !== $finalValue
                ];
            }

            if ($force) {
                $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            
            return [
                'success' => true,
                'affected_rows' => $affectedRows,
                'message' => 'Row updated successfully',
                'changes_made' => $successLog['successful_changes']
            ];
            
        } catch (Exception $e) {
            if ($force) {
                try {
                    $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
                } catch (Exception $restoreException) {
                    \Log::warning('Failed to restore foreign key checks', [
                        'error' => $restoreException->getMessage()
                    ]);
                }
            }
            
            \Log::error('Row update failed with exception', [
                'database' => $database->database,
                'table' => $tableName,
                'primary_keys' => $primaryKeyValues,
                'update_data' => $updateData,
                'processed_data' => $validatedData ?? null,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine()
            ]);

            $errorMessage = $e->getMessage();
            $isForeignKeyConstraint = (
                strpos($errorMessage, 'foreign key constraint fails') !== false ||
                strpos($errorMessage, 'FOREIGN KEY constraint failed') !== false ||
                strpos($errorMessage, 'Cannot add or update a child row') !== false ||
                strpos($errorMessage, 'Cannot delete or update a parent row') !== false
            );
            
            if ($isForeignKeyConstraint) {
                return [
                    'success' => false,
                    'error' => 'Konstantin\'s foreign key constraint issue: ' . $errorMessage,
                    'foreign_key_error' => true,
                    'error_type' => 'foreign_key_constraint',
                    'error_details' => [
                        'type' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'raw_message' => $errorMessage
                    ]
                ];
            }
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_details' => [
                    'type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ];
        }
    }

    public function insertTableRow(Database $database, string $tableName, array $rowData): array
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

            $columns = $connection->query("
                SELECT column_name, data_type, is_nullable, column_default, extra
                FROM information_schema.columns 
                WHERE table_schema = '{$database->database}' 
                AND table_name = '{$tableName}'
                ORDER BY ordinal_position
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $columnMap = [];
            foreach ($columns as $column) {
                $columnMap[$column['column_name']] = $column;
            }

            $validatedData = [];
            foreach ($rowData as $columnName => $value) {
                if (!isset($columnMap[$columnName])) {
                    throw new Exception("Column {$columnName} does not exist");
                }
                
                $columnInfo = $columnMap[$columnName];

                if (strpos($columnInfo['extra'], 'auto_increment') !== false && empty($value)) {
                    continue;
                }

                if ($value === null && $columnInfo['is_nullable'] === 'NO' && 
                    strpos($columnInfo['extra'], 'auto_increment') === false) {
                    throw new Exception("Column {$columnName} cannot be null");
                }

                if (in_array(strtolower($columnInfo['data_type']), ['boolean', 'bool', 'tinyint']) && 
                    is_string($value) && !empty($value)) {
                    $lowerValue = strtolower(trim($value));
                    if ($lowerValue === 'true') {
                        $value = '1';
                    } elseif ($lowerValue === 'false') {
                        $value = '0';
                    }
                }
                
                $validatedData[$columnName] = $value;
            }
            
            if (empty($validatedData)) {
                throw new Exception('No valid data to insert');
            }

            $columns = array_keys($validatedData);
            $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
            
            $sql = "INSERT INTO `{$database->database}`.`{$tableName}` (`" . 
                   implode('`, `', $columns) . "`) VALUES (" . 
                   implode(', ', $placeholders) . ")";
            
            $params = [];
            foreach ($validatedData as $col => $value) {
                $params[':' . $col] = $value;
            }
            
            $stmt = $connection->prepare($sql);
            $result = $stmt->execute($params);
            
            if (!$result) {
                throw new Exception('Failed to insert row');
            }
            
            return [
                'success' => true,
                'message' => 'Row inserted successfully',
                'inserted_id' => $connection->lastInsertId(),
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function dropTable(Database $database, string $tableName, bool $forceDrop = false): array
    {
        try {

            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic');

            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $tableName)) {
                \Log::error("Invalid table name format: {$tableName}");
                throw new Exception("Invalid table name format: {$tableName}");
            }

            $stmt = $connection->getPdo()->prepare("
                SELECT COUNT(*) FROM information_schema.tables 
                WHERE table_schema = ? AND table_name = ?
            ");
            $stmt->execute([$database->database, $tableName]);
            $tableExists = $stmt->fetchColumn();
            
            if (!$tableExists) {
                \Log::error("Table does not exist: {$tableName} in database: {$database->database}");
                throw new Exception("Table '{$tableName}' does not exist in database '{$database->database}'");
            }
            
            if (!$forceDrop) {
                $foreignKeys = $connection->select("
                    SELECT 
                        CONSTRAINT_NAME,
                        TABLE_NAME,
                        COLUMN_NAME,
                        REFERENCED_TABLE_NAME,
                        REFERENCED_COLUMN_NAME
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE REFERENCED_TABLE_SCHEMA = ? 
                    AND REFERENCED_TABLE_NAME = ?
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ", [$database->database, $tableName]);
                
                if (!empty($foreignKeys)) {
                    $referencingTables = array_unique(array_column($foreignKeys, 'TABLE_NAME'));
                    return [
                        'success' => false,
                        'error' => "Cannot drop table '{$tableName}' because it is referenced by foreign key constraints in tables: " . 
                                 implode(', ', $referencingTables) . ". Please drop the referencing tables first or remove the foreign key constraints.",
                        'foreign_key_error' => true,
                        'referencing_tables' => $referencingTables
                    ];
                }
            } else {
                $connection->statement("SET FOREIGN_KEY_CHECKS = 0");
            }

            $connection->statement("DROP TABLE `{$database->database}`.`{$tableName}`");
            
            if ($forceDrop) {
                $connection->statement("SET FOREIGN_KEY_CHECKS = 1");
            }
            
            return [
                'success' => true,
                'message' => "Table '{$tableName}' dropped successfully",
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function dropColumn(Database $database, string $tableName, string $columnName): array
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

            $columnExists = $connection->query("
                SELECT COUNT(*) FROM information_schema.columns 
                WHERE table_schema = '{$database->database}' 
                AND table_name = '{$tableName}' 
                AND column_name = '{$columnName}'
            ")->fetchColumn();
            
            if (!$columnExists) {
                throw new Exception('Column does not exist');
            }

            $columnCount = $connection->query("
                SELECT COUNT(*) FROM information_schema.columns 
                WHERE table_schema = '{$database->database}' 
                AND table_name = '{$tableName}'
            ")->fetchColumn();
            
            if ($columnCount <= 1) {
                throw new Exception('Cannot drop the last column in a table');
            }
            
            $sql = "ALTER TABLE `{$database->database}`.`{$tableName}` DROP COLUMN `{$columnName}`";
            $connection->exec($sql);
            
            return [
                'success' => true,
                'message' => 'Column dropped successfully',
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}