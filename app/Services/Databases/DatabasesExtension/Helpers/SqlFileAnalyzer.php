<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Helpers;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class SqlFileAnalyzer
{
    public function analyzeSqlFile(UploadedFile $file): array
    {
        try {
            $content = $this->extractSqlContent($file);
            
            return [
                'success' => true,
                'tables' => $this->extractTableInfo($content),
                'file_size' => $file->getSize(),
                'file_name' => $file->getClientOriginalName(),
                'estimated_rows' => $this->estimateRowCount($content),
            ];
        } catch (Exception $e) {
            Log::error('SQL file analysis failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'tables' => [],
            ];
        }
    }

    public function extractSqlContent(UploadedFile $file): string
    {
        $filename = $file->getClientOriginalName();
        $tempPath = $file->getRealPath();
        
        try {
            if (str_ends_with($filename, '.gz')) {
                return $this->extractGzipContent($tempPath);
            } elseif (str_ends_with($filename, '.bz2')) {
                return $this->extractBzip2Content($tempPath);
            } elseif (str_ends_with($filename, '.zip')) {
                return $this->extractZipContent($tempPath);
            } elseif (str_ends_with($filename, '.tar')) {
                return $this->extractTarContent($tempPath);
            } elseif (str_ends_with($filename, '.7z')) {
                return $this->extract7zContent($tempPath);
            } elseif (str_ends_with($filename, '.rar')) {
                return $this->extractRarContent($tempPath);
            }
            
            return file_get_contents($tempPath);
            
        } catch (Exception $e) {
            Log::warning('Failed to extract compressed content, trying as plain file', [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            
            return file_get_contents($tempPath);
        }
    }

    protected function extractGzipContent(string $filePath): string
    {
        if (function_exists('gzopen')) {
            $handle = gzopen($filePath, 'rb');
            if ($handle) {
                $content = '';
                while (!gzeof($handle)) {
                    $content .= gzread($handle, 8192);
                }
                gzclose($handle);
                return $content;
            }
        }
        
        if (function_exists('gzfile')) {
            $lines = gzfile($filePath);
            if ($lines !== false) {
                return implode('', $lines);
            }
        }
        
        if ($this->isCommandAvailable('gunzip')) {
            $tempFile = tempnam(sys_get_temp_dir(), 'gz_extract_');
            $safeCommand = ['gunzip', '-c', $filePath];
            $command = implode(' ', array_map('escapeshellarg', $safeCommand)) . ' > ' . escapeshellarg($tempFile) . ' 2>&1';
            $result = shell_exec($command);
            if (file_exists($tempFile) && filesize($tempFile) > 0) {
                $content = file_get_contents($tempFile);
                unlink($tempFile);
                return $content;
            }
            if (file_exists($tempFile)) unlink($tempFile);
        }
        
        throw new Exception('Unable to extract GZIP content');
    }

    protected function extractBzip2Content(string $filePath): string
    {
        if (function_exists('bzopen')) {
            $handle = bzopen($filePath, 'r');
            if ($handle) {
                $content = '';
                while (!feof($handle)) {
                    $content .= bzread($handle, 8192);
                }
                bzclose($handle);
                return $content;
            }
        }
        
        if ($this->isCommandAvailable('bunzip2')) {
            $tempFile = tempnam(sys_get_temp_dir(), 'bz2_extract_');
            $safeCommand = ['bunzip2', '-c', $filePath];
            $command = implode(' ', array_map('escapeshellarg', $safeCommand)) . ' > ' . escapeshellarg($tempFile) . ' 2>&1';
            $result = shell_exec($command);
            if (file_exists($tempFile) && filesize($tempFile) > 0) {
                $content = file_get_contents($tempFile);
                unlink($tempFile);
                return $content;
            }
            if (file_exists($tempFile)) unlink($tempFile);
        }
        
        throw new Exception('Unable to extract BZIP2 content');
    }

    protected function extractZipContent(string $filePath): string
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive class not available');
        }
        
        $zip = new \ZipArchive();
        $result = $zip->open($filePath);
        
        if ($result !== TRUE) {
            throw new Exception("Failed to open ZIP file: error code $result");
        }
        
        try {
            $content = '';
            
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                $fileInfo = $zip->statIndex($i);
                
                if ($fileInfo['size'] == 0) {
                    continue;
                }
                
                if (str_ends_with(strtolower($filename), '.sql')) {
                    $fileContent = $zip->getFromIndex($i);
                    if ($fileContent !== false) {
                        $content .= $fileContent . "\n";
                    }
                }
            }
            
            $zip->close();
            
            if (empty($content)) {
                throw new Exception('No SQL files found in ZIP archive');
            }
            
            return $content;
            
        } catch (Exception $e) {
            $zip->close();
            throw $e;
        }
    }

    protected function extractTarContent(string $filePath): string
    {
        if (!class_exists('PharData')) {
            throw new Exception('PharData class not available');
        }
        
        try {
            $tar = new \PharData($filePath);
            $content = '';
            
            foreach ($tar as $file) {
                if ($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.sql')) {
                    $content .= $file->getContent() . "\n";
                }
            }
            
            if (empty($content)) {
                throw new Exception('No SQL files found in TAR archive');
            }
            
            return $content;
            
        } catch (Exception $e) {
            throw new Exception('Failed to extract TAR content: ' . $e->getMessage());
        }
    }

    protected function extract7zContent(string $filePath): string
    {
        if ($this->isCommandAvailable('7z')) {
            $tempDir = sys_get_temp_dir() . '/7z_extract_' . uniqid();
            mkdir($tempDir, 0700);
            
            try {
                $safeCommand = ['7z', 'x', $filePath, '-o' . $tempDir, '*.sql'];
                $command = implode(' ', array_map('escapeshellarg', $safeCommand)) . ' 2>&1';
                $result = shell_exec($command);
                
                $content = '';
                $sqlFiles = glob($tempDir . '/*.sql');
                
                foreach ($sqlFiles as $sqlFile) {
                    $content .= file_get_contents($sqlFile) . "\n";
                }
                
                array_map('unlink', glob($tempDir . '/*'));
                rmdir($tempDir);
                
                if (empty($content)) {
                    throw new Exception('No SQL files found in 7Z archive');
                }
                
                return $content;
                
            } catch (Exception $e) {
                if (is_dir($tempDir)) {
                    array_map('unlink', glob($tempDir . '/*'));
                    rmdir($tempDir);
                }
                throw $e;
            }
        }
        
        throw new Exception('7z command not available');
    }

    protected function extractRarContent(string $filePath): string
    {
        if ($this->isCommandAvailable('unrar')) {
            $tempDir = sys_get_temp_dir() . '/rar_extract_' . uniqid();
            mkdir($tempDir, 0700);
            
            try {
                $safeCommand = ['unrar', 'x', $filePath, $tempDir . '/', '*.sql'];
                $command = implode(' ', array_map('escapeshellarg', $safeCommand)) . ' 2>&1';
                $result = shell_exec($command);
                
                $content = '';
                $sqlFiles = glob($tempDir . '/*.sql');
                
                foreach ($sqlFiles as $sqlFile) {
                    $content .= file_get_contents($sqlFile) . "\n";
                }
                
                array_map('unlink', glob($tempDir . '/*'));
                rmdir($tempDir);
                
                if (empty($content)) {
                    throw new Exception('No SQL files found in RAR archive');
                }
                
                return $content;
                
            } catch (Exception $e) {
                if (is_dir($tempDir)) {
                    array_map('unlink', glob($tempDir . '/*'));
                    rmdir($tempDir);
                }
                throw $e;
            }
        }
        
        throw new Exception('unrar command not available');
    }

    protected function isCommandAvailable(string $command): bool
    {
        $safeCommand = ['which', $command];
        $result = shell_exec(implode(' ', array_map('escapeshellarg', $safeCommand)) . ' 2>/dev/null');
        return !empty($result);
    }

    protected function extractTableInfo(string $content): array
    {
        $tables = [];
        
        $cleanContent = $this->cleanSqlContent($content);
        
        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\((.*?)\)(?:[^;]*)?;/is', $cleanContent, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $tableName = $match[1];
            $tableDefinition = $match[2];
            
            $columns = $this->extractColumns($tableDefinition);
            $estimatedRows = $this->estimateTableRows($content, $tableName);
            
            $tables[] = [
                'name' => $tableName,
                'columns' => $columns,
                'estimated_rows' => $estimatedRows,
                'has_data' => $estimatedRows > 0,
                'primary_keys' => $this->extractPrimaryKeys($tableDefinition),
            ];
        }
        
        return $tables;
    }

    protected function extractColumns(string $tableDefinition): array
    {
        $columns = [];
        
        $parts = $this->smartSplit($tableDefinition, ',');
        
        foreach ($parts as $part) {
            $part = trim($part);
            
            if (preg_match('/^\s*(PRIMARY\s+KEY|KEY|INDEX|UNIQUE|CONSTRAINT|FOREIGN\s+KEY)/i', $part)) {
                continue;
            }
            
            if (preg_match('/^[`"]?(\w+)[`"]?\s+(\w+(?:\([^)]*\))?)/i', $part, $matches)) {
                $columnName = $matches[1];
                $dataType = $matches[2];
                
                $columns[] = [
                    'name' => $columnName,
                    'type' => $dataType,
                    'nullable' => !preg_match('/NOT\s+NULL/i', $part),
                    'default' => $this->extractDefault($part),
                    'auto_increment' => preg_match('/AUTO_INCREMENT/i', $part),
                ];
            }
        }
        
        return $columns;
    }

    protected function extractPrimaryKeys(string $tableDefinition): array
    {
        if (preg_match('/PRIMARY\s+KEY\s*\(\s*([^)]+)\s*\)/i', $tableDefinition, $matches)) {
            $keyColumns = explode(',', $matches[1]);
            return array_map(function($col) {
                return trim($col, ' `"');
            }, $keyColumns);
        }
        
        return [];
    }

    protected function extractDefault(string $columnDef): ?string
    {
        if (preg_match('/DEFAULT\s+([^,\s]+)/i', $columnDef, $matches)) {
            return trim($matches[1], '\'"');
        }
        
        return null;
    }

    protected function smartSplit(string $text, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inQuotes = false;
        $quoteChar = '';
        
        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];
            
            if (!$inQuotes && ($char === '"' || $char === "'")) {
                $inQuotes = true;
                $quoteChar = $char;
            } elseif ($inQuotes && $char === $quoteChar) {
                $inQuotes = false;
                $quoteChar = '';
            } elseif (!$inQuotes) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === $delimiter && $depth === 0) {
                    $parts[] = $current;
                    $current = '';
                    continue;
                }
            }
            
            $current .= $char;
        }
        
        if ($current !== '') {
            $parts[] = $current;
        }
        
        return $parts;
    }

    protected function cleanSqlContent(string $content): string
    {
        $content = preg_replace('/--.*$/m', '', $content);
        
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        
        $content = preg_replace('/\s+/', ' ', $content);
        
        return trim($content);
    }

    protected function estimateRowCount(string $content): int
    {
        $totalRows = 0;
        
        preg_match_all('/INSERT\s+INTO\s+[^;]+;/is', $content, $matches);
        
        foreach ($matches[0] as $insertStatement) {
            $totalRows += $this->countRowsInInsertStatement($insertStatement);
        }
        
        return $totalRows;
    }

    protected function estimateTableRows(string $content, string $tableName): int
    {
        $totalRows = 0;
        
        preg_match_all('/INSERT\s+INTO\s+[`"]?' . preg_quote($tableName) . '[`"]?[^;]+;/is', $content, $matches);
        
        foreach ($matches[0] as $insertStatement) {
            $totalRows += $this->countRowsInInsertStatement($insertStatement);
        }
        
        return $totalRows;
    }

    protected function countRowsInInsertStatement(string $insertStatement): int
    {
        
        if (preg_match('/VALUES\s*(.+)$/is', $insertStatement, $matches)) {
            $valuesSection = $matches[1];
            
            $rowCount = 0;
            $inQuotes = false;
            $quoteChar = '';
            $parenDepth = 0;
            $length = strlen($valuesSection);
            
            for ($i = 0; $i < $length; $i++) {
                $char = $valuesSection[$i];
                
                if (!$inQuotes && ($char === '"' || $char === "'")) {
                    $inQuotes = true;
                    $quoteChar = $char;
                } elseif ($inQuotes && $char === $quoteChar) {
                    if ($i > 0 && $valuesSection[$i - 1] === '\\') {
                        continue;
                    }
                    $inQuotes = false;
                    $quoteChar = '';
                } elseif (!$inQuotes) {
                    if ($char === '(') {
                        if ($parenDepth === 0) {
                            $rowCount++;
                        }
                        $parenDepth++;
                    } elseif ($char === ')') {
                        $parenDepth--;
                    }
                }
            }
            
            return $rowCount;
        }
        
        return 1;
    }

    public function extractSelectedTables(string $content, array $selectedTables): string
    {
        if (empty($selectedTables)) {
            return $content;
        }
        
        $result = '';
        
        $result .= "-- Selective SQL Export\n";
        $result .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $result .= "-- Selected tables: " . implode(', ', $selectedTables) . "\n\n";
        $result .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $result .= "SET AUTOCOMMIT = 0;\n";
        $result .= "START TRANSACTION;\n";
        $result .= "SET time_zone = \"+00:00\";\n";
        $result .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        
        foreach ($selectedTables as $tableName) {
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?' . preg_quote($tableName) . '[`"]?.*?;/is', $content, $matches)) {
                $result .= "-- Table structure for table `{$tableName}`\n";
                $result .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
                $result .= $matches[0] . "\n\n";
            }
        }
        
        foreach ($selectedTables as $tableName) {
            preg_match_all('/INSERT\s+INTO\s+[`"]?' . preg_quote($tableName) . '[`"]?.*?;/is', $content, $insertMatches);
            if (!empty($insertMatches[0])) {
                $result .= "-- Dumping data for table `{$tableName}`\n";
                foreach ($insertMatches[0] as $insert) {
                    $result .= $insert . "\n";
                }
                $result .= "\n";
            }
        }
        
        $result .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $result .= "COMMIT;\n";
        
        return $result;
    }
}
