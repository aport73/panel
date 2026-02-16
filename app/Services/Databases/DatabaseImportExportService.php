<?php

namespace Pterodactyl\Services\Databases;

use Exception;
use PDO;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\SqlDynDatabaseConnection;
use Pterodactyl\Services\Databases\DatabasesExtension\CompressionHandler;
use Pterodactyl\Services\Databases\DatabasesExtension\Security\SecureFileValidator;
use Pterodactyl\Services\Databases\DatabasesExtension\Security\FileLockManager;
use Pterodactyl\Services\Databases\DatabasesExtension\Security\SecureLogger;
use Pterodactyl\Exceptions\Services\Database\DatabaseImportException;
use Pterodactyl\Exceptions\Services\Database\DatabaseExportException;
use Pterodactyl\Services\Databases\DatabasesExtension\Security\SqlSecurityValidator;
use Pterodactyl\Services\Databases\DatabasesExtension\Security\SqlParserValidator;
use Pterodactyl\Services\Databases\DatabasesExtension\Validation\ContentValidator;
use Pterodactyl\Services\Databases\DatabasesExtension\Export\DatabaseExporter;
use Pterodactyl\Services\Databases\DatabasesExtension\Utils\ConnectionTester;
use Pterodactyl\Services\Databases\DatabasesExtension\Management\TableManager;
use Pterodactyl\Services\Databases\DatabasesExtension\Management\DatabaseSearcher;
use Pterodactyl\Services\Databases\DatabasesExtension\Management\RawSqlExecutor;
use Pterodactyl\Services\Databases\DatabasesExtension\Helpers\SqlFileAnalyzer;
use Pterodactyl\Services\Databases\DatabasesExtension\Import\SelectiveImporter;
use Pterodactyl\Services\Databases\DatabasesExtension\Import\PhpMyAdminStreamingImporter;
use Pterodactyl\Services\Databases\DatabasesExtension\Import\SimplePhpMyAdminImporter;
use Pterodactyl\Services\Databases\DatabasesExtension\Import\LargeFileImporter;
use Pterodactyl\Services\Databases\DatabasesExtension\Import\MemoryEfficientImportHelper;
use Pterodactyl\Services\Databases\DatabasesExtension\Compatibility\SqlCompatibilityHandler;

class DatabaseImportExportService
{
    use SqlSecurityValidator, SqlParserValidator, ContentValidator;
    use DatabaseExporter, ConnectionTester, TableManager, DatabaseSearcher, RawSqlExecutor;


    protected CompressionHandler $compressionHandler;
    protected SecureFileValidator $fileValidator;
    protected SqlCompatibilityHandler $compatibilityHandler;

    public function __construct(
        protected SqlDynDatabaseConnection $dynamic,
        protected SqlFileAnalyzer $fileAnalyzer,
        protected SelectiveImporter $selectiveImporter
    ) {
        $this->compressionHandler = new CompressionHandler();
        $this->fileValidator = new SecureFileValidator();
        $this->compatibilityHandler = new SqlCompatibilityHandler();
    }

    public function importDatabase(Database $database, UploadedFile $file, string $mode = 'merge', bool $force = false): bool
    {
        $fileSize = $file->getSize();
        $fileName = $file->getClientOriginalName();
        
        SecureLogger::logFileOperation('import_request', $fileName, $fileSize, 'database_import');
        
        $resourceId = FileLockManager::generateResourceId('import', $database->database);
        
        return FileLockManager::withLock($resourceId, function() use ($database, $file, $mode, $force, $fileSize) {
            if ($fileSize > 50 * 1024 * 1024) {
                return $this->processLargeFileInBackground($database, $file, $mode, $force);
            }
            
            return $this->processFileImmediately($database, $file, $mode, $force);
        });
    }

    private function processLargeFileInBackground(Database $database, UploadedFile $file, string $mode, bool $force): bool
    {
        try {
            $tempPath = $this->storeTempFile($file);
            
            $job = new \Pterodactyl\Jobs\DatabaseImportJob(
                $database, 
                $tempPath, 
                $mode, 
                $force, 
                auth()->id()
            );
            
            dispatch($job);
            
            error_log("BACKGROUND IMPORT: Job dispatched for large file, job ID: " . $job->getJobId());
            
            return true;
            
        } catch (Exception $e) {
            error_log("BACKGROUND DISPATCH ERROR: " . $e->getMessage());
            throw new DatabaseImportException('Failed to start background import: ' . $e->getMessage());
        }
    }

    private function processFileImmediately(Database $database, UploadedFile $file, string $mode, bool $force): bool
    {
        $originalMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '128M');
        
        try {
            $this->fileValidator->validateUploadedFile($file, $force);
            
            return $this->routeToAppropriateImporter($database, $file, $mode, $force);
            
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            error_log("Import failed: " . $errorMessage);
            
            if (strpos($errorMessage, "doesn't exist") !== false) {
                throw new DatabaseImportException('Import partially completed. Some tables referenced in the dump do not exist. This may be a partial dump or tables may be in wrong order. Try using "Force Import" mode to skip missing dependencies.');
            }
            
            if (strpos($errorMessage, "already exists") !== false) {
                throw new DatabaseImportException('Import failed due to existing objects. Use "Wipe Database" mode to replace existing data, or "Merge" mode with "Force Import" to skip conflicts.');
            }
            
            if (strpos($errorMessage, "Access denied") !== false) {
                throw new DatabaseImportException('Database access denied. Check database user permissions.');
            }
            
            throw new DatabaseImportException('Import failed: ' . $errorMessage . '. Try using "Force Import" mode to skip problematic statements.');
        } finally {
            ini_set('memory_limit', $originalMemoryLimit);
        }
    }

    private function routeToAppropriateImporter(Database $database, UploadedFile $file, string $mode, bool $force): bool
    {
        $fileSize = $file->getSize();
        $tempDir = sys_get_temp_dir();
        $tempSqlFile = $tempDir . '/pterodactyl_import_' . uniqid() . '.sql';
        
        error_log("IMPORT ROUTING: File size: {$fileSize} bytes, Mode: {$mode}, Force: " . ($force ? 'true' : 'false'));
        
        try {
            $this->extractToTempFile($file, $tempSqlFile);
            
            $isPhpMyAdminDump = MemoryEfficientImportHelper::isPhpMyAdminDump($tempSqlFile);
            
            if ($isPhpMyAdminDump) {
                error_log("ROUTING: phpMyAdmin dump detected, using SimplePhpMyAdminImporter with mode: {$mode}");
                $importer = new SimplePhpMyAdminImporter();
                return $importer->import($database, $tempSqlFile, $mode, $force);
            } else {
                error_log("ROUTING: Generic SQL dump detected, using direct MySQL import with mode: {$mode}");
                return $this->directMysqlImport($database, $tempSqlFile, $mode);
            }
            
        } finally {
            if (file_exists($tempSqlFile)) {
                unlink($tempSqlFile);
            }
        }
    }

    private function directMysqlImport(Database $database, string $tempSqlFile, string $mode): bool
    {
        if ($mode === 'wipe') {
            $this->wipeDatabaseTables($database);
        }
        
        $this->preprocessGenericSqlFile($tempSqlFile);
        
        $host = $database->host;
        $command = MemoryEfficientImportHelper::buildMysqlCommand($database, $host, $tempSqlFile, $mode);
        
        error_log("Executing MySQL import: " . preg_replace('/-p[^\s]+/', '-p***', $command));
        
        $result = MemoryEfficientImportHelper::executeMysqlCommand($command, 300);
        
        if ($result['success']) {
            error_log("MySQL import completed successfully");
            return true;
        } else {
            error_log("MySQL import failed: " . $result['error']);
            throw new DatabaseImportException("MySQL import failed: " . $result['error']);
        }
    }

    private function wipeDatabaseTables(Database $database): void
    {
        try {
            error_log("WIPE MODE: Dropping all tables from database {$database->database}");
            
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $connection->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            $stmt = $connection->prepare("SHOW TABLES");
            $stmt->execute();
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $droppedCount = 0;
            foreach ($tables as $table) {
                try {
                    $connection->exec("DROP TABLE IF EXISTS `{$table}`");
                    $droppedCount++;
                    error_log("WIPE: Dropped table `{$table}`");
                } catch (\PDOException $e) {
                    error_log("WIPE ERROR: Failed to drop table `{$table}`: " . $e->getMessage());
                }
            }
            
            $connection->exec("SET FOREIGN_KEY_CHECKS = 1");
            
            error_log("WIPE COMPLETED: Dropped {$droppedCount} tables from database {$database->database}");
            
        } catch (\Exception $e) {
            error_log("WIPE DATABASE ERROR: " . $e->getMessage());
        }
    }

    private function storeTempFile(UploadedFile $file): string
    {
        $tempDir = sys_get_temp_dir() . '/pterodactyl_imports';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $tempPath = $tempDir . '/upload_' . uniqid() . '_' . $file->getClientOriginalName();
        $file->move(dirname($tempPath), basename($tempPath));
        
        return $tempPath;
    }

    private function extractToTempFile(UploadedFile $file, string $tempSqlFile): void
    {
        try {
            error_log("EXTRACTION: Starting extraction for file: " . $file->getClientOriginalName());
            
            $sqlContent = $this->compressionHandler->extract($file);
            
            $bytesWritten = file_put_contents($tempSqlFile, $sqlContent);
            
            if ($bytesWritten === false) {
                throw new DatabaseImportException('Failed to write extracted content to temporary file');
            }
            
            error_log("EXTRACTION: Successfully extracted {$bytesWritten} bytes");
            
        } catch (Exception $e) {
            error_log("EXTRACTION: Failed with error: " . $e->getMessage());
            throw new DatabaseImportException("Failed to extract file: " . $e->getMessage());
        }
    }

    private function preprocessGenericSqlFile(string $tempSqlFile): void
    {
        $content = file_get_contents($tempSqlFile);
        
        $content = $this->applyUniversalCompatibilityFixes($content);
        
        $compatibilityHandler = new SqlCompatibilityHandler();
        $content = $compatibilityHandler->makeCompatible($content);
        
        $sessionSettings = "-- Enhanced MariaDB compatibility settings\n";
        $sessionSettings .= "SET SESSION sql_mode='';\n";
        $sessionSettings .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $sessionSettings .= "SET UNIQUE_CHECKS=0;\n";
        $sessionSettings .= "SET AUTOCOMMIT=0;\n\n";
        
        $endSettings = "\n-- Re-enable constraints\n";
        $endSettings .= "COMMIT;\n";
        $endSettings .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $endSettings .= "SET UNIQUE_CHECKS=1;\n";
        $endSettings .= "SET AUTOCOMMIT=1;\n";
        
        $finalContent = $sessionSettings . $content . $endSettings;
        file_put_contents($tempSqlFile, $finalContent);
        
        error_log("Preprocessed generic SQL file: " . strlen($finalContent) . " chars");
    }

    private function applyUniversalCompatibilityFixes(string $content): string
    {
        $content = preg_replace('/\/\*!\d+\s+([^*]+)\s+\*\//', '$1', $content);
        $content = preg_replace('/\/\*!\d+[^*]*\*\//', '', $content);
        
        $content = str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $content);
        
        $content = str_replace("'0000-00-00 00:00:00'", 'NULL', $content);
        $content = str_replace("'0000-00-00'", 'NULL', $content);
        
        $content = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s+/', '', $content);
        
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/\n\s*\n/', "\n", $content);
        
        return $content;
    }






    public function importFromCredentials(Database $database, array $sourceCredentials, string $mode = 'merge', bool $force = false, bool $selective = false, array $selectedTables = [], array $selectedColumns = []): bool
    {
        throw new DatabaseImportException('Import from credentials not yet implemented in simplified service');
    }

    public function importSelectedTables(Database $database, UploadedFile $file, array $selectedTables, array $selectedColumns, string $mode = 'merge'): bool
    {
        try {
            return $this->selectiveImporter->importSelectedTables($database, $file, $selectedTables, $selectedColumns, $mode);
        } catch (Exception $e) {
            error_log("Selective import failed: " . $e->getMessage());
            throw new DatabaseImportException('Selective import failed: ' . $e->getMessage());
        }
    }

    public function getDatabaseInfo(Database $database): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $stmt = $connection->prepare("SELECT COUNT(*) as table_count FROM information_schema.tables WHERE table_schema = ?");
            $stmt->execute([$database->database]);
            $tableCount = $stmt->fetchColumn();
            
            $stmt = $connection->prepare("
                SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb 
                FROM information_schema.tables 
                WHERE table_schema = ?
            ");
            $stmt->execute([$database->database]);
            $sizeMb = $stmt->fetchColumn() ?: 0;
            
            return [
                'table_count' => (int) $tableCount,
                'size_mb' => (float) $sizeMb,
                'error' => null
            ];
            
        } catch (Exception $e) {
            error_log("Database info error: " . $e->getMessage());
            return [
                'table_count' => 0,
                'size_mb' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getTableColumns(PDO $connection, string $tableName): array
    {
        $stmt = $connection->prepare("
            SELECT COLUMN_NAME 
            FROM information_schema.columns 
            WHERE table_schema = DATABASE() AND table_name = ?
            ORDER BY ordinal_position
        ");
        $stmt->execute([$tableName]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getDatabaseContents(Database $database): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $stmt = $connection->prepare("SHOW TABLES");
            $stmt->execute();
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $tableData = [];
            $totalRecords = 0;
            
            foreach ($tables as $table) {
                $stmt = $connection->prepare("SELECT COUNT(*) FROM `{$table}`");
                $stmt->execute();
                $rowCount = $stmt->fetchColumn();
                $totalRecords += $rowCount;
                
                $stmt = $connection->prepare("
                    SELECT 
                        COLUMN_NAME as column_name,
                        DATA_TYPE as data_type,
                        IS_NULLABLE as is_nullable,
                        COLUMN_DEFAULT as column_default,
                        COLUMN_KEY as column_key,
                        EXTRA as extra,
                        COLUMN_COMMENT as column_comment
                    FROM information_schema.columns 
                    WHERE table_schema = ? AND table_name = ?
                    ORDER BY ordinal_position
                ");
                $stmt->execute([$database->database, $table]);
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $sampleData = [];
                if ($rowCount > 0) {
                    $stmt = $connection->prepare("SELECT * FROM `{$table}` LIMIT 3");
                    $stmt->execute();
                    $sampleData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                $sizeStmt = $connection->prepare("
                    SELECT ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb 
                    FROM information_schema.tables 
                    WHERE table_schema = ? AND table_name = ?
                ");
                $sizeStmt->execute([$database->database, $table]);
                $tableSizeMb = $sizeStmt->fetchColumn() ?: 0;

                $tableData[] = [
                    'name' => $table,
                    'rows' => (int) $rowCount,
                    'size_mb' => (float) $tableSizeMb,
                    'comment' => '',
                    'columns' => $columns,
                    'sample_data' => $sampleData
                ];
            }
            
            return [
                'tables' => $tableData,
                'total_records' => $totalRecords
            ];
            
        } catch (Exception $e) {
            error_log("Database contents error: " . $e->getMessage());
            return [
                'tables' => [],
                'total_records' => 0
            ];
        }
    }

    public function getTableData(Database $database, string $tableName, int $page = 1, int $limit = 50): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $offset = ($page - 1) * $limit;
            
            $stmt = $connection->prepare("SELECT COUNT(*) FROM `{$tableName}`");
            $stmt->execute();
            $totalCount = $stmt->fetchColumn();
            
            $stmt = $connection->prepare("
                SELECT 
                    COLUMN_NAME as column_name,
                    DATA_TYPE as data_type,
                    IS_NULLABLE as is_nullable,
                    COLUMN_DEFAULT as column_default,
                    COLUMN_KEY as column_key,
                    EXTRA as extra,
                    COLUMN_COMMENT as column_comment
                FROM information_schema.columns 
                WHERE table_schema = ? AND table_name = ?
                ORDER BY ordinal_position
            ");
            $stmt->execute([$database->database, $tableName]);
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $connection->prepare("SELECT * FROM `{$tableName}` LIMIT {$limit} OFFSET {$offset}");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'data' => $data,
                'columns' => $columns,
                'total' => (int) $totalCount,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($totalCount / $limit)
            ];
            
        } catch (Exception $e) {
            error_log("Table data error: " . $e->getMessage());
            throw new DatabaseImportException("Failed to get table data: " . $e->getMessage());
        }
    }

    public function updateTableRow(Database $database, string $tableName, array $primaryKeyValues, array $updateData, bool $force = false): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $whereClause = [];
            $whereValues = [];
            foreach ($primaryKeyValues as $column => $value) {
                $whereClause[] = "`{$column}` = ?";
                $whereValues[] = $value;
            }
            
            $setClause = [];
            $setValues = [];
            foreach ($updateData as $column => $value) {
                $setClause[] = "`{$column}` = ?";
                $setValues[] = $value;
            }
            
            $sql = "UPDATE `{$tableName}` SET " . implode(', ', $setClause) . " WHERE " . implode(' AND ', $whereClause);
            
            $stmt = $connection->prepare($sql);
            $stmt->execute(array_merge($setValues, $whereValues));
            
            return [
                'success' => true,
                'affected_rows' => $stmt->rowCount(),
                'message' => 'Row updated successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Update row error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function deleteTableRow(Database $database, string $tableName, array $primaryKeyValues, bool $force = false): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $whereClause = [];
            $whereValues = [];
            foreach ($primaryKeyValues as $column => $value) {
                $whereClause[] = "`{$column}` = ?";
                $whereValues[] = $value;
            }
            
            $sql = "DELETE FROM `{$tableName}` WHERE " . implode(' AND ', $whereClause);
            
            $stmt = $connection->prepare($sql);
            $stmt->execute($whereValues);
            
            return [
                'success' => true,
                'affected_rows' => $stmt->rowCount(),
                'message' => 'Row deleted successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Delete row error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function createTable(Database $database, string $tableName, array $columns): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $columnDefinitions = [];
            $primaryKeys = [];
            
            foreach ($columns as $column) {
                $definition = "`{$column['name']}` {$column['type']}";
                
                if (isset($column['length']) && $column['length']) {
                    $definition .= "({$column['length']})";
                }
                
                if (!$column['nullable']) {
                    $definition .= " NOT NULL";
                }
                
                if (isset($column['default']) && $column['default'] !== '') {
                    $definition .= " DEFAULT '{$column['default']}'";
                }
                
                if ($column['auto_increment']) {
                    $definition .= " AUTO_INCREMENT";
                }
                
                if ($column['primary_key']) {
                    $primaryKeys[] = "`{$column['name']}`";
                }
                
                $columnDefinitions[] = $definition;
            }
            
            if (!empty($primaryKeys)) {
                $columnDefinitions[] = "PRIMARY KEY (" . implode(', ', $primaryKeys) . ")";
            }
            
            $sql = "CREATE TABLE `{$tableName}` (" . implode(', ', $columnDefinitions) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $connection->exec($sql);
            
            return [
                'success' => true,
                'message' => 'Table created successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Create table error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function addColumn(Database $database, string $tableName, array $columnData): array
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $definition = "`{$columnData['name']}` {$columnData['type']}";
            
            if (isset($columnData['length']) && $columnData['length']) {
                $definition .= "({$columnData['length']})";
            }
            
            if (!$columnData['nullable']) {
                $definition .= " NOT NULL";
            }
            
            if (isset($columnData['default']) && $columnData['default'] !== '') {
                $definition .= " DEFAULT '{$columnData['default']}'";
            }
            
            if ($columnData['auto_increment']) {
                $definition .= " AUTO_INCREMENT";
            }
            
            $sql = "ALTER TABLE `{$tableName}` ADD COLUMN {$definition}";
            
            if (isset($columnData['after']) && $columnData['after']) {
                $sql .= " AFTER `{$columnData['after']}`";
            }
            
            $connection->exec($sql);
            
            return [
                'success' => true,
                'message' => 'Column added successfully'
            ];
            
        } catch (Exception $e) {
            error_log("Add column error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function analyzeImportFile(UploadedFile $file): array
    {
        try {
            $tempDir = sys_get_temp_dir();
            $tempSqlFile = $tempDir . '/analyze_' . uniqid() . '.sql';
            
            $this->extractToTempFile($file, $tempSqlFile);
            
            $analysis = [
                'success' => true,
                'file_size' => $file->getSize(),
                'file_name' => $file->getClientOriginalName(),
                'dump_type' => 'unknown',
                'estimated_tables' => 0,
                'estimated_records' => 0,
                'estimated_rows' => 0,
                'compatibility_issues' => [],
                'warnings' => [],
                'applied_fixes' => [],
                'tables' => []
            ];
            
            $content = file_get_contents($tempSqlFile);
            
            if (MemoryEfficientImportHelper::isPhpMyAdminDump($tempSqlFile)) {
                if (strpos($content, '-- phpMyAdmin SQL Dump') !== false) {
                    $analysis['dump_type'] = 'phpMyAdmin';
                } elseif (strpos($content, '-- MariaDB dump') !== false) {
                    $analysis['dump_type'] = 'MariaDB';
                } else {
                    $analysis['dump_type'] = 'MySQL';
                }
            } else {
                $analysis['dump_type'] = 'Generic SQL';
            }
            
            if (strpos($content, 'CREATE TABLE') === false && strpos($content, 'INSERT INTO') === false) {
                $analysis['warnings'][] = 'Dump appears to be empty or contains only routines/procedures';
                if (strpos($content, 'Dumping routines for database') !== false) {
                    $analysis['warnings'][] = 'This appears to be a routines-only dump with no table data';
                }
            }
            
            $analysis['estimated_tables'] = substr_count($content, 'CREATE TABLE');
            $analysis['estimated_records'] = substr_count($content, 'INSERT INTO');
            $analysis['estimated_rows'] = $analysis['estimated_records'];
            
            $analysis['tables'] = $this->parseTableStructureFromDump($content);
            
            if (strpos($content, 'utf8mb4_0900_ai_ci') !== false) {
                $analysis['compatibility_issues'][] = 'MySQL 8.0 collation detected';
                $analysis['applied_fixes'][] = 'Will convert utf8mb4_0900_ai_ci to utf8mb4_unicode_ci';
            }
            
            if (strpos($content, 'DEFINER=') !== false) {
                $analysis['compatibility_issues'][] = 'DEFINER clauses detected';
                $analysis['applied_fixes'][] = 'Will remove DEFINER clauses';
            }
            
            if (strpos($content, "'0000-00-00") !== false) {
                $analysis['compatibility_issues'][] = 'Zero dates detected';
                $analysis['applied_fixes'][] = 'Will convert zero dates to NULL';
            }
            
            if (preg_match('/\/\*!\d+/', $content)) {
                $analysis['compatibility_issues'][] = 'MySQL version-specific comments detected';
                $analysis['applied_fixes'][] = 'Will process MySQL version comments';
            }
            
            if ($analysis['estimated_tables'] > 100) {
                $analysis['warnings'][] = 'Large number of tables detected - import may take longer';
            }
            
            if ($file->getSize() > 50 * 1024 * 1024) {
                $analysis['warnings'][] = 'Large file size - will use background processing';
            }
            
            if (file_exists($tempSqlFile)) {
                unlink($tempSqlFile);
            }
            
            return $analysis;
            
        } catch (Exception $e) {
            error_log("File analysis error: " . $e->getMessage());
            return [
                'file_size' => $file->getSize(),
                'file_name' => $file->getClientOriginalName(),
                'dump_type' => 'unknown',
                'estimated_tables' => 0,
                'estimated_records' => 0,
                'compatibility_issues' => [],
                'warnings' => ['Analysis failed: ' . $e->getMessage()],
                'applied_fixes' => []
            ];
        }
    }

    private function getDatabaseConnection(Database $database): \PDO
    {
        $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
        $connection = DB::connection('dynamic')->getPdo();
        
        $connection->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $connection->exec("SET CHARACTER SET utf8mb4");
        $connection->exec("SET collation_connection = utf8mb4_unicode_ci");
        
        $connection->exec("SET sql_mode = 'TRADITIONAL,NO_AUTO_VALUE_ON_ZERO'");
        
        $connection->exec("SET time_zone = '+00:00'");
        
        return $connection;
    }

    private function parseTableStructureFromDump(string $content): array
    {
        $tables = [];
        
        preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([^`\s]+)`?\s*\((.*?)\)\s*(?:ENGINE|TYPE|;)/is', $content, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $tableName = trim($match[1], '`');
            $tableDefinition = $match[2];
            
            $columns = [];
            $lines = explode(',', $tableDefinition);
            
            foreach ($lines as $line) {
                $line = trim($line);
                
                if (preg_match('/^\s*`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+([a-zA-Z]+)/i', $line, $colMatch)) {
                    $columnName = trim($colMatch[1], '`');
                    $dataType = strtoupper($colMatch[2]);
                    
                    if (!in_array($dataType, ['PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'FOREIGN', 'CONSTRAINT'])) {
                        $columns[] = [
                            'column_name' => $columnName,
                            'data_type' => $dataType,
                            'is_nullable' => strpos($line, 'NOT NULL') === false ? 'YES' : 'NO',
                            'column_key' => strpos($line, 'PRIMARY KEY') !== false ? 'PRI' : '',
                            'extra' => strpos($line, 'AUTO_INCREMENT') !== false ? 'auto_increment' : '',
                        ];
                    }
                }
            }
            
            $recordCount = substr_count($content, "INSERT INTO `$tableName`") + substr_count($content, "INSERT INTO $tableName");
            
            $transformedColumns = [];
            $primaryKeys = [];
            
            foreach ($columns as $column) {
                $transformedColumns[] = [
                    'name' => $column['column_name'],
                    'type' => $column['data_type'],
                    'nullable' => $column['is_nullable'] === 'YES',
                    'default' => null,
                    'auto_increment' => $column['extra'] === 'auto_increment'
                ];
                
                if ($column['column_key'] === 'PRI') {
                    $primaryKeys[] = $column['column_name'];
                }
            }

            $tables[] = [
                'name' => $tableName,
                'columns' => $transformedColumns,
                'estimated_rows' => $recordCount,
                'has_data' => $recordCount > 0,
                'primary_keys' => $primaryKeys,
                'selectable' => true
            ];
        }
        
        return $tables;
    }
}