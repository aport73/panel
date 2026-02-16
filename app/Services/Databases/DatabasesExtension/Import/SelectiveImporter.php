<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Import;

use Exception;
use PDO;
use Pterodactyl\Models\Database;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Services\Databases\DatabasesExtension\Helpers\SqlFileAnalyzer;
use Pterodactyl\Exceptions\Services\Database\DatabaseImportException;

class SelectiveImporter
{
    public function __construct(
        protected SqlFileAnalyzer $fileAnalyzer
    ) {
    }

    public function importSelectedTables(
        Database $database, 
        UploadedFile $file, 
        array $selectedTables,
        array $selectedColumns = [],
        string $mode = 'merge'
    ): bool {
        try {
            $sqlContent = $this->fileAnalyzer->extractSqlContent($file);
            
            $filteredSql = $this->fileAnalyzer->extractSelectedTables($sqlContent, $selectedTables);
            
            if (!empty($selectedColumns)) {
                $filteredSql = $this->applyColumnFiltering($filteredSql, $selectedColumns);
            }
            
            return $this->executeSql($database, $filteredSql, $mode, $selectedTables);
            
        } catch (Exception $e) {
            Log::error('Selective import failed: ' . $e->getMessage());
            throw new DatabaseImportException('Selective import failed: ' . $e->getMessage());
        }
    }

    protected function applyColumnFiltering(string $sqlContent, array $selectedColumns): string
    {
        $result = '';
        $lines = explode("\n", $sqlContent);
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            if (preg_match('/^CREATE\s+TABLE\s+[`"]?(\w+)[`"]?\s*\(/i', $trimmedLine, $matches)) {
                $tableName = $matches[1];
                if (isset($selectedColumns[$tableName])) {
                    $line = $this->filterCreateTableColumns($line, $selectedColumns[$tableName]);
                }
            }
            elseif (preg_match('/^INSERT\s+INTO\s+[`"]?(\w+)[`"]?/i', $trimmedLine, $matches)) {
                $tableName = $matches[1];
                if (isset($selectedColumns[$tableName])) {
                    $line = $this->filterInsertColumns($line, $selectedColumns[$tableName]);
                }
            }
            
            $result .= $line . "\n";
        }
        
        return $result;
    }

    protected function filterCreateTableColumns(string $createStatement, array $selectedColumns): string
    {
        return $createStatement;
    }

    protected function filterInsertColumns(string $insertStatement, array $selectedColumns): string
    {
        if (!preg_match('/INSERT\s+INTO\s+[`"]?(\w+)[`"]?\s*\(\s*([^)]+)\s*\)\s*VALUES\s*\((.*)\)/i', $insertStatement, $matches)) {
            return $insertStatement;
        }
        
        $tableName = $matches[1];
        $columnsPart = $matches[2];
        $valuesPart = $matches[3];
        
        $columns = array_map(function($col) {
            return trim($col, ' `"');
        }, explode(',', $columnsPart));
        
        $selectedIndexes = [];
        foreach ($selectedColumns as $selectedCol) {
            $index = array_search($selectedCol, $columns);
            if ($index !== false) {
                $selectedIndexes[] = $index;
            }
        }
        
        if (empty($selectedIndexes)) {
            return '-- Skipped: No selected columns found';
        }
        
        $filteredColumns = [];
        foreach ($selectedIndexes as $index) {
            $filteredColumns[] = '`' . $columns[$index] . '`';
        }
        
        $values = $this->parseValues($valuesPart);
        $filteredValues = [];
        
        foreach ($values as $value) {
            $filteredValues[] = $selectedIndexes[$value] ?? 'NULL';
        }
        
        return "INSERT INTO `{$tableName}` (" . implode(', ', $filteredColumns) . ") VALUES (" . implode(', ', $filteredValues) . ")";
    }

    protected function parseValues(string $valuesPart): array
    {
        $values = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        
        for ($i = 0; $i < strlen($valuesPart); $i++) {
            $char = $valuesPart[$i];
            
            if (!$inQuotes && ($char === '"' || $char === "'")) {
                $inQuotes = true;
                $quoteChar = $char;
            } elseif ($inQuotes && $char === $quoteChar) {
                $inQuotes = false;
                $quoteChar = '';
            } elseif (!$inQuotes && $char === ',') {
                $values[] = trim($current);
                $current = '';
                continue;
            }
            
            $current .= $char;
        }
        
        if ($current !== '') {
            $values[] = trim($current);
        }
        
        return $values;
    }

    protected function executeSql(Database $database, string $sql, string $mode, array $selectedTables = []): bool
    {
        try {
            config(['database.connections.selective_import' => [
                'driver' => 'mysql',
                'host' => $database->host->host,
                'port' => $database->host->port,
                'database' => $database->database,
                'username' => $database->username,
                'password' => decrypt($database->password),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'options' => [
                    PDO::ATTR_TIMEOUT => 30,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ],
            ]]);

            $connection = DB::connection('selective_import');
            $pdo = $connection->getPdo();
            
            if ($mode === 'wipe') {
                $this->wipeAllTables($pdo);
            } else {
                $this->clearSelectedTables($pdo, $selectedTables);
            }
            
            $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $pdo->exec("SET AUTOCOMMIT = 0");
            $pdo->exec("START TRANSACTION");
            
            $statements = $this->splitSqlStatements($sql);
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (empty($statement) || str_starts_with($statement, '--')) {
                    continue;
                }
                
                try {
                    $pdo->exec($statement);
                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    Log::warning('SQL statement failed during selective import: ' . $e->getMessage(), [
                        'statement' => substr($statement, 0, 200) . '...'
                    ]);
                    
                    if (stripos($statement, 'CREATE TABLE') !== false) {
                        Log::error('Critical CREATE TABLE statement failed, continuing anyway');
                    }
                }
            }
            
            $pdo->exec("COMMIT");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $pdo->exec("SET AUTOCOMMIT = 1");
            
            return true;
            
        } catch (Exception $e) {
            Log::error('Selective import execution failed: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function wipeAllTables(PDO $pdo): void
    {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            }
            
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        } catch (Exception $e) {
            Log::error("Failed to wipe database tables: " . $e->getMessage());
            throw $e;
        }
    }

    protected function wipeSelectedTables(PDO $pdo, array $tableNames): void
    {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            
            foreach ($tableNames as $tableName) {
                try {
                    $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
                } catch (Exception $e) {
                    Log::warning("Failed to drop table {$tableName}: " . $e->getMessage());
                }
            }
            
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        } catch (Exception $e) {
            Log::error("Failed to wipe selected tables: " . $e->getMessage());
            throw $e;
        }
    }

    protected function clearSelectedTables(PDO $pdo, array $tableNames): void
    {
        foreach ($tableNames as $tableName) {
            try {
                $pdo->exec("DELETE FROM `{$tableName}`");
            } catch (Exception $e) {
                Log::warning("Failed to clear table {$tableName}: " . $e->getMessage());
            }
        }
    }

    protected function extractTableNames(string $sql): array
    {
        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i', $sql, $matches);
        return $matches[1] ?? [];
    }

    protected function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        $length = strlen($sql);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            
            if (!$inQuotes && ($char === '"' || $char === "'")) {
                $inQuotes = true;
                $quoteChar = $char;
            } elseif ($inQuotes && $char === $quoteChar) {
                if ($i + 1 < $length && $sql[$i + 1] === $quoteChar) {
                    $current .= $char . $sql[$i + 1];
                    $i++;
                    continue;
                }
                $inQuotes = false;
                $quoteChar = '';
            }
            
            if (!$inQuotes && $char === ';') {
                $trimmed = trim($current);
                if (!empty($trimmed) && !str_starts_with($trimmed, '--')) {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }
            
            $current .= $char;
        }
        
        $trimmed = trim($current);
        if (!empty($trimmed) && !str_starts_with($trimmed, '--')) {
            $statements[] = $trimmed;
        }
        
        return $statements;
    }

    public function getImportPreview(UploadedFile $file, array $selectedTables): array
    {
        try {
            $analysis = $this->fileAnalyzer->analyzeSqlFile($file);
            
            if (!$analysis['success']) {
                return $analysis;
            }
            
            $selectedTableData = array_filter($analysis['tables'], function($table) use ($selectedTables) {
                return in_array($table['name'], $selectedTables);
            });
            
            $totalRows = array_sum(array_column($selectedTableData, 'estimated_rows'));
            
            return [
                'success' => true,
                'selected_tables' => array_values($selectedTableData),
                'total_tables' => count($selectedTableData),
                'total_estimated_rows' => $totalRows,
                'file_info' => [
                    'name' => $analysis['file_name'],
                    'size' => $analysis['file_size'],
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
