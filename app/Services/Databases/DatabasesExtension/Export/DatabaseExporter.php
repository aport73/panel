<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Export;

use Exception;
use PDO;
use Pterodactyl\Models\Database;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Pterodactyl\Exceptions\Service\Database\DatabaseExportException;

trait DatabaseExporter
{
    public function exportDatabase(Database $database, array $options = []): StreamedResponse
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $filename = $database->database . '_' . date('Y-m-d_H-i-s') . '.sql';
            
            return new StreamedResponse(function() use ($connection, $database, $options) {
                try {
                $this->streamDatabaseExport($connection, $database, $options);
                } catch (Exception $streamException) {
                    \Illuminate\Support\Facades\Log::error("EXPORT: Stream callback failed", [
                        'error' => $streamException->getMessage(),
                        'trace' => $streamException->getTraceAsString()
                    ]);
                    echo "-- Export failed in stream: " . $streamException->getMessage() . "\n";
                }
            }, 200, [
                'Content-Type' => 'application/sql',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
            
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error("EXPORT: exportDatabase failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new DatabaseExportException('Failed to export database: ' . $e->getMessage());
        }
    }

    public function streamDatabaseExport(PDO $connection, Database $database, array $options): void
    {
        try {
            $sqlContent = $this->generateNativeDump($database, $options);
            echo $sqlContent;
            
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error("EXPORT: Native dump failed, falling back to PHP export", [
                'error' => $e->getMessage(),
                'database' => $database->database
            ]);
            
            try {
                $this->streamDatabaseExportLegacy($connection, $database, $options);
            } catch (Exception $legacyException) {
                echo "-- Export failed: Database export could not be completed\n";
                echo "-- Error: " . $legacyException->getMessage() . "\n";
                \Illuminate\Support\Facades\Log::error("EXPORT: Both native and legacy export failed", [
                    'error' => $legacyException->getMessage()
                ]);
            }
        }
    }
    
    private function generateNativeDump(Database $database, array $options): string
    {
        if (isset($options['columns']) && !empty($options['columns'])) {
            throw new Exception("Column-selective export requires PHP fallback");
        }
        
        if (isset($options['selective']) && $options['selective'] === true) {
            throw new Exception("Selective export requires PHP fallback");
        }
        
        $tempCredFile = tempnam(sys_get_temp_dir(), 'mysql_cred_');
        $tempOutputFile = tempnam(sys_get_temp_dir(), 'mysql_dump_');
        
        try {
            $sqlDynConnection = app(\Pterodactyl\Extensions\SqlDynDatabaseConnection::class);
            $sqlDynConnection->setWithDatabaseCredentials('temp_for_dump', $database);
            $connectionConfig = config('database.connections.temp_for_dump');
            $decryptedPassword = $connectionConfig['password'];
            
            $credContent = "[client]\n";
            $credContent .= "user={$database->username}\n";
            $credContent .= "password={$decryptedPassword}\n";
            $credContent .= "host={$database->host->host}\n";
            $credContent .= "port={$database->host->port}\n";
            
            
            file_put_contents($tempCredFile, $credContent);
            chmod($tempCredFile, 0600);
            
            try {
                $testPdo = new \PDO(
                    "mysql:host={$database->host->host};port={$database->host->port};dbname={$database->database}",
                    $database->username,
                    $decryptedPassword,
                    [
                        \PDO::ATTR_TIMEOUT => 30,
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
                    ]
                );
                $testPdo->query("SELECT 1")->fetch();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("EXPORT: Database connection test failed", [
                    'error' => $e->getMessage()
                ]);
                throw new \Exception("Database connection failed: " . $e->getMessage());
            }
            
            $command = $this->buildMysqlDumpCommand($tempCredFile, $database, $tempOutputFile, $options);
            
            
            $whichExitCode = $this->checkCommandAvailability('mysqldump');
            
            $descriptorspec = [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"]
            ];
            
            $process = proc_open($command, $descriptorspec, $pipes);
            
            if (is_resource($process)) {
                fclose($pipes[0]);
                
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                
                $exitCode = proc_close($process);
                
                
                $output = array_merge(
                    $stdout ? explode("\n", $stdout) : [],
                    $stderr ? explode("\n", $stderr) : []
                );
            } else {
                throw new \Exception("Failed to start mysqldump process");
            }
            
            if ($exitCode !== 0) {
                $errorOutput = implode("\n", $output);
                
                
                \Illuminate\Support\Facades\Log::error("EXPORT: mysqldump failed", [
                    'exit_code' => $exitCode
                ]);
                throw new \Exception("mysqldump failed with exit code {$exitCode}: {$errorOutput}");
            }
            
            if (!file_exists($tempOutputFile) || filesize($tempOutputFile) === 0) {
                throw new \Exception("mysqldump produced no output");
            }
            
            $sqlContent = file_get_contents($tempOutputFile);
            
            
            return $sqlContent;
            
        } finally {
            if (file_exists($tempCredFile)) {
                unlink($tempCredFile);
            }
            if (file_exists($tempOutputFile)) {
                unlink($tempOutputFile);
            }
        }
    }
    
    private function buildMysqlDumpCommand(string $credFile, Database $database, string $outputFile, array $options): string
    {
        $command = "mysqldump";
        $command .= " --defaults-extra-file=" . escapeshellarg($credFile);
        $command .= " --single-transaction";
        $command .= " --routines";
        $command .= " --triggers";
        $command .= " --events";
        
        
        $command .= " --lock-tables=false";
        $command .= " --add-drop-table";
        $command .= " --create-options";
        $command .= " --disable-keys";
        $command .= " --extended-insert";
        $command .= " --hex-blob";
        $command .= " --no-autocommit";
        
        if (isset($options['structure_only']) && $options['structure_only']) {
            $command .= " --no-data";
        } elseif (isset($options['data_only']) && $options['data_only']) {
            $command .= " --no-create-info";
        }
        
        $selectedTables = $options['selected_tables'] ?? $options['tables'] ?? [];
        if (!empty($selectedTables)) {
            foreach ($selectedTables as $table) {
                $command .= " " . escapeshellarg($table);
            }
        }
        
        $command .= " " . escapeshellarg($database->database);
        $command .= " > " . escapeshellarg($outputFile);
        
        return $command;
    }

    private function checkCommandAvailability(string $command): int
    {
        $allowedCommands = ['mysqldump', 'mysql'];
        
        if (!in_array($command, $allowedCommands)) {
            return 1;
        }
        
        $safeCommand = ['which', $command];
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        
        $process = proc_open(implode(' ', array_map('escapeshellarg', $safeCommand)) . ' 2>/dev/null', $descriptorspec, $pipes);
        
        if (is_resource($process)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return proc_close($process);
        }
        
        return 1;
    }
    
    private function generateSqlContentDirect(PDO $connection, Database $database, array $options): string
    {
        $content = '';
        
        $content .= $this->getSqlHeader($database);
        
        $allTables = $this->getAllTables($connection);
        $selectedTables = $this->getSelectedTables($allTables, $options);
        
        foreach ($selectedTables as $tableName) {
            $content .= $this->getTableStructureAsString($connection, $tableName);
            
            $selectedColumns = $this->getSelectedColumns($tableName, $options);
            $content .= $this->getTableDataAsString($connection, $tableName, $selectedColumns);
        }
        
        $content .= $this->getSqlFooter();
        
        return $content;
    }
    
    private function getAllTables(PDO $connection): array
    {
        $stmt = $connection->query("SHOW TABLES");
        $tables = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        return $tables;
    }

    private function getTableStructureAsString(PDO $connection, string $tableName): string
    {
        $content = '';
        
        $content .= "\n--\n-- Table structure for table `{$tableName}`\n--\n\n";
        $content .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
        
        $stmt = $connection->prepare("SHOW CREATE TABLE `{$tableName}`");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && isset($result['Create Table'])) {
            $content .= $result['Create Table'] . ";\n\n";
        }
        
        return $content;
    }
    
    private function getTableDataAsString(PDO $connection, string $tableName, ?array $selectedColumns = null): string
    {
        $content = '';
        
        $countStmt = $connection->prepare("SELECT COUNT(*) as count FROM `{$tableName}`");
        $countStmt->execute();
        $rowCount = $countStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        if ($rowCount == 0) {
            return $content;
        }
        
        $content .= "\n--\n-- Dumping data for table `{$tableName}`\n--\n\n";
        $content .= "LOCK TABLES `{$tableName}` WRITE;\n";
        $content .= "/*!40000 ALTER TABLE `{$tableName}` DISABLE KEYS */;\n";
        
        $columns = $selectedColumns ?: $this->getTableColumns($connection, $tableName);
        $columnList = '`' . implode('`, `', $columns) . '`';
        
        $batchSize = 1000;
        $offset = 0;
        
        do {
            $query = "SELECT {$columnList} FROM `{$tableName}` LIMIT {$batchSize} OFFSET {$offset}";
            $stmt = $connection->prepare($query);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($rows)) {
                $content .= $this->getInsertStatementsAsString($tableName, $columns, $rows);
            }
            
            $offset += $batchSize;
        } while (count($rows) === $batchSize);
        
        $content .= "/*!40000 ALTER TABLE `{$tableName}` ENABLE KEYS */;\n";
        $content .= "UNLOCK TABLES;\n\n";
        
        return $content;
    }
    
    private function getInsertStatementsAsString(string $tableName, array $columns, array $rows): string
    {
        if (empty($rows)) {
            return '';
        }
        
        $content = "INSERT INTO `{$tableName}` (`" . implode('`, `', $columns) . "`) VALUES\n";
        
        $valueStrings = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                        $values[] = '0x' . bin2hex($value);
                    } else {
                        $values[] = "'" . addslashes($value) . "'";
                    }
                }
            }
            $valueStrings[] = '(' . implode(', ', $values) . ')';
        }
        
        $content .= implode(",\n", $valueStrings) . ";\n\n";
        
        return $content;
    }
    
    private function getSqlHeader(Database $database): string
    {
        return "-- MySQL dump generated by Pterodactyl\n" .
               "-- Host: localhost    Database: " . $database->database . "\n" .
               "-- ------------------------------------------------------\n\n" .
               "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n" .
               "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n" .
               "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n" .
               "/*!40101 SET NAMES utf8mb4 */;\n" .
               "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n" .
               "/*!40103 SET TIME_ZONE='+00:00' */;\n" .
               "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n" .
               "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n" .
               "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n" .
               "/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n\n";
    }
    
    private function getSqlFooter(): string
    {
        return "\n/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n" .
               "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n" .
               "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n" .
               "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n" .
               "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n" .
               "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n" .
               "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n" .
               "/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n\n" .
               "-- Dump completed\n";
    }

    private function streamDatabaseExportLegacy(PDO $connection, Database $database, array $options): void
    {
        $originalErrorReporting = error_reporting(0);
        
        try {
        $connection->setAttribute(PDO::ATTR_TIMEOUT, 3600);
        $this->outputExportHeader($database, $options);
            
            $allTables = $connection->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $tablesToExport = $this->getSelectedTables($allTables, $options);
        
            foreach ($tablesToExport as $table) {
            if (!isset($options['data_only']) || !$options['data_only']) {
                    $this->exportTableStructure($connection, $table, $options);
            }

            if (!isset($options['structure_only']) || !$options['structure_only']) {
                    $this->exportTableData($connection, $table, $options);
            }
        }

        $this->outputExportFooter();
            
        } finally {
            error_reporting($originalErrorReporting);
        }
    }

    protected function outputExportHeader(Database $database, array $options): void
    {
        echo "-- Database Export\n";
        echo "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        echo "-- Database: {$database->database}\n";
        echo "-- Host: {$database->host->host}:{$database->host->port}\n";
        echo "\n";
        echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        echo "SET AUTOCOMMIT = 0;\n";
        echo "START TRANSACTION;\n";
        echo "SET time_zone = \"+00:00\";\n";
        echo "\n";
        echo "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
        echo "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n";
        echo "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n";
        echo "/*!40101 SET NAMES utf8mb4 */;\n";
        echo "\n";
        echo "USE `{$database->database}`;\n";
        echo "\n";
    }




    protected function outputExportFooter(): void
    {
        echo "\nCOMMIT;\n";
        echo "\n";
        echo "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n";
        echo "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n";
        echo "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n";
    }

    protected function getSelectedTables(array $allTables, array $options): array
    {
        $selectedTables = $options['selected_tables'] ?? $options['tables'] ?? [];
        
        if (empty($selectedTables)) {
            return $allTables;
        }

        $filteredTables = array_filter($allTables, function($table) use ($selectedTables) {
            return in_array($table, $selectedTables);
        });
        
        return array_values($filteredTables);
    }

    protected function getSelectedColumns(string $table, array $options): ?array
    {
        $columns = $options['selected_columns'] ?? $options['columns'] ?? [];
        
        if (!isset($columns[$table]) || empty($columns[$table])) {
            return null;
        }

        return $columns[$table];
    }

    protected function exportTableStructure(PDO $connection, string $table, array $options = []): void
    {
        echo "-- --------------------------------------------------------\n";
        echo "-- Table structure for table `{$table}`\n";
        echo "-- --------------------------------------------------------\n\n";
        
        $selectedColumns = $this->getSelectedColumns($table, $options);
        
        if ($selectedColumns === null) {
            echo "DROP TABLE IF EXISTS `{$table}`;\n";
            
            try {
                $createStmt = $connection->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
                if ($createStmt && isset($createStmt['Create Table'])) {
                    echo $createStmt['Create Table'] . ";\n\n";
                } else {
                    echo "-- ERROR: Could not get table structure for `{$table}`\n\n";
                    \Illuminate\Support\Facades\Log::warning("EXPORT: Failed to get table structure", ['table' => $table, 'result' => $createStmt]);
                }
            } catch (Exception $e) {
                echo "-- ERROR: Exception getting table structure for `{$table}`: {$e->getMessage()}\n\n";
                \Illuminate\Support\Facades\Log::error("EXPORT: Exception getting table structure", ['table' => $table, 'error' => $e->getMessage()]);
            }
        } else {
            $this->exportCustomTableStructure($connection, $table, $selectedColumns);
        }
    }

    protected function exportCustomTableStructure(PDO $connection, string $table, array $selectedColumns): void
    {
        echo "DROP TABLE IF EXISTS `{$table}`;\n";
        
        $columnsQuery = "
            SELECT 
                COLUMN_NAME,
                COLUMN_TYPE,
                IS_NULLABLE,
                COLUMN_DEFAULT,
                EXTRA,
                COLUMN_KEY,
                COLUMN_COMMENT
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ";
        
        $stmt = $connection->prepare($columnsQuery);
        $stmt->execute([$table]);
        $allColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $filteredColumns = array_filter($allColumns, function($col) use ($selectedColumns) {
            return in_array($col['COLUMN_NAME'], $selectedColumns);
        });
        
        if (empty($filteredColumns)) {
            echo "-- No valid columns selected for table `{$table}`\n\n";
            return;
        }
        
        echo "CREATE TABLE `{$table}` (\n";
        
        $columnDefs = [];
        $primaryKeys = [];
        
        foreach ($filteredColumns as $col) {
            $def = "  `{$col['COLUMN_NAME']}` {$col['COLUMN_TYPE']}";
            
            if ($col['IS_NULLABLE'] === 'NO') {
                $def .= ' NOT NULL';
            }
            
            if ($col['COLUMN_DEFAULT'] !== null) {
                $def .= " DEFAULT '{$col['COLUMN_DEFAULT']}'";
            }
            
            if ($col['EXTRA']) {
                $def .= " {$col['EXTRA']}";
            }
            
            if ($col['COLUMN_COMMENT']) {
                $def .= " COMMENT '{$col['COLUMN_COMMENT']}'";
            }
            
            $columnDefs[] = $def;
            
            if ($col['COLUMN_KEY'] === 'PRI') {
                $primaryKeys[] = $col['COLUMN_NAME'];
            }
        }
        
        echo implode(",\n", $columnDefs);
        
        if (!empty($primaryKeys)) {
            echo ",\n  PRIMARY KEY (`" . implode('`, `', $primaryKeys) . "`)";
        }
        
        echo "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";
    }

    protected function exportTableData(PDO $connection, string $table, array $options = []): void
    {
        $selectedColumns = $this->getSelectedColumns($table, $options);
        $whereClause = $this->getWhereClause($table, $options);
        $limit = $this->getRowLimit($table, $options);
        
        $columnList = $selectedColumns ? 
            '`' . implode('`, `', $selectedColumns) . '`' : '*';
        
        $countQuery = "SELECT COUNT(*) FROM `{$table}`";
        if ($whereClause) {
            $countQuery .= " WHERE {$whereClause}";
        }
        
        try {
            $rowCount = $connection->query($countQuery)->fetchColumn();
        if ($rowCount == 0) {
                return;
            }
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error("EXPORT: Failed to count rows", ['table' => $table, 'error' => $e->getMessage()]);
            return;
        }
        
        if ($limit && $limit < $rowCount) {
            $rowCount = $limit;
        }
        
        echo "-- Dumping data for table `{$table}`\n";
        echo "-- {$rowCount} rows" . ($selectedColumns ? " (selected columns only)" : "") . "\n\n";
        
        $batchSize = 1000;
        $offset = 0;
        
        while ($offset < $rowCount) {
            $query = "SELECT {$columnList} FROM `{$table}`";
            if ($whereClause) {
                $query .= " WHERE {$whereClause}";
            }
            $query .= " LIMIT {$batchSize} OFFSET {$offset}";
            
            try {
                $stmt = $connection->query($query);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            if (empty($rows)) {
                    break;
                }
                
                $this->outputInsertStatements($table, $rows, $selectedColumns);
                
            } catch (Exception $e) {
                \Illuminate\Support\Facades\Log::error("EXPORT: Data query failed", [
                    'table' => $table,
                    'query' => $query,
                    'error' => $e->getMessage()
                ]);
                break;
            }
            
            $offset += $batchSize;

            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }
        
        echo "\n";
    }

    protected function getWhereClause(string $table, array $options): ?string
    {
        if (!isset($options['row_filters']) || !isset($options['row_filters'][$table])) {
            return null;
        }

        return $options['row_filters'][$table];
    }

    protected function getRowLimit(string $table, array $options): ?int
    {
        if (!isset($options['row_limits']) || !isset($options['row_limits'][$table])) {
            return null;
        }

        return (int) $options['row_limits'][$table];
    }

    protected function outputInsertStatements(string $table, array $rows, ?array $selectedColumns = null): void
    {
        if (empty($rows)) {
            return;
        }

        $columns = $selectedColumns ?: array_keys($rows[0]);
        $columnList = '`' . implode('`, `', $columns) . '`';
        
        echo "INSERT INTO `{$table}` ({$columnList}) VALUES\n";
        
        $valueStrings = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                        $values[] = '0x' . bin2hex($value);
                    } else {
                    $values[] = "'" . addslashes($value) . "'";
                    }
                }
            }
            $valueStrings[] = '(' . implode(', ', $values) . ')';
        }
        
        $insertStatement = implode(",\n", $valueStrings) . ";\n";
        echo $insertStatement;
        
    }

    public function exportDatabaseCompressed(Database $database, string $format = 'sql', array $options = []): StreamedResponse
    {
        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            
            $baseFilename = $database->database . '_' . date('Y-m-d_H-i-s');
            
            $sqlContent = $this->generateSqlContent($connection, $database, $options);
            
            if ($format !== 'sql') {
                $compressionHandler = new \Pterodactyl\Services\Databases\DatabasesExtension\CompressionHandler();
                $compressed = $compressionHandler->compress($sqlContent, $format, $baseFilename);
                
                return new StreamedResponse(function() use ($compressed) {
                    echo $compressed['content'];
                }, 200, [
                    'Content-Type' => $compressed['mime'],
                    'Content-Disposition' => 'attachment; filename="' . $compressed['filename'] . '"',
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
            }
            
            return new StreamedResponse(function() use ($sqlContent) {
                echo $sqlContent;
            }, 200, [
                'Content-Type' => 'application/sql',
                'Content-Disposition' => 'attachment; filename="' . $baseFilename . '.sql"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
            
        } catch (Exception $e) {
            throw new DatabaseExportException('Failed to export database: ' . $e->getMessage());
        }
    }

    protected function generateSqlContent(PDO $connection, Database $database, array $options): string
    {
        try {
            $sqlContent = $this->generateNativeDump($database, $options);
            return $sqlContent;
            
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::warning("EXPORT: Native dump failed, using PHP export with output buffering", [
                'error' => $e->getMessage()
            ]);
            
            try {
                $sqlContent = $this->generateSqlContentDirect($connection, $database, $options);
                
                if (!empty($sqlContent) && !mb_check_encoding($sqlContent, 'UTF-8')) {
                    \Illuminate\Support\Facades\Log::warning("EXPORT: SQL content has encoding issues, attempting to fix");
                    $sqlContent = mb_convert_encoding($sqlContent, 'UTF-8', 'auto');
                }
                
                if (empty($sqlContent)) {
                    throw new Exception("Generated content is empty");
                }
                
                if (strpos($sqlContent, '<!DOCTYPE') === 0 || strpos($sqlContent, '<html') !== false || strpos($sqlContent, '<body') !== false) {
                    \Illuminate\Support\Facades\Log::error("EXPORT: Generated content contains HTML", [
                        'content_start' => substr($sqlContent, 0, 500)
                    ]);
                    
                    if (preg_match('/^(.*?)<!DOCTYPE/s', $sqlContent, $matches)) {
                        $cleanSql = trim($matches[1]);
                        if (!empty($cleanSql) && strpos($cleanSql, '--') === 0) {
                            \Illuminate\Support\Facades\Log::warning("EXPORT: Extracted SQL from HTML contaminated content");
                            $sqlContent = $cleanSql;
                        } else {
                            throw new Exception("Generated content is HTML instead of SQL");
                        }
                    } else {
                        throw new Exception("Generated content is HTML instead of SQL");
                    }
                }
                
                if (preg_match('/<(div|span|p|table|tr|td|script|style|head|meta)/i', $sqlContent)) {
                    \Illuminate\Support\Facades\Log::error("EXPORT: Generated content contains HTML tags", [
                        'content_start' => substr($sqlContent, 0, 500)
                    ]);
                    throw new Exception("Generated content contains HTML tags");
                }
                
                return $sqlContent;
                
            } catch (Exception $bufferException) {
                ob_end_clean();
                throw new Exception("Failed to generate SQL content: " . $bufferException->getMessage());
            }
        }
    }
}