<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Utils;

use Exception;
use PDO;
use Pterodactyl\Models\Database;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Exceptions\Service\Database\DatabaseImportException;

trait SqlProcessor
{
    protected function processSqlContent(string $sqlContent, PDO $connection, Database $database): void
    {
        $statements = preg_split('/;(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', $sqlContent);

        if ($statements === false) {
            logger()->warning('Regex split failed, falling back to simple semicolon split');
            $statements = explode(';', $sqlContent);
        }

        
        $batchStatements = [];
        $batchSize = 50;
        $processedCount = 0;
        
        foreach ($statements as $index => $statement) {
            $statement = trim($statement);
            
            if (empty($statement)) {
                continue;
            }
            
            if (!$this->isValidSqlStatement($statement)) {
                continue;
            }

            if (strlen($statement) > 10485760) { 
                logger()->warning('Skipping extremely large statement', [
                    'statement_index' => $index,
                    'statement_size_mb' => round(strlen($statement) / 1024 / 1024, 2),
                    'statement_preview' => substr($statement, 0, 100)
                ]);
                continue;
            }

            if (strlen($statement) > 1048576) { 
                $splitStatements = $this->splitLargeInsertStatement($statement);
                foreach ($splitStatements as $splitStatement) {
                    $batchStatements[] = $splitStatement;
                }
            } else {
                $batchStatements[] = $statement;
            }

            if (count($batchStatements) >= $batchSize) {
                $this->executeBatchWithReconnection($batchStatements, $database, $connection);
                $processedCount += count($batchStatements);
                $batchStatements = [];

                if ($processedCount % 500 === 0) {
                    gc_collect_cycles();
                }
            }
        }

        if (!empty($batchStatements)) {
            $this->executeBatchWithReconnection($batchStatements, $database, $connection);
            $processedCount += count($batchStatements);
        }
    }

    protected function isValidSqlStatement(string $statement): bool
    {
        $statement = trim($statement);

        if (empty($statement)) {
            return false;
        }

        if (preg_match('/^(\/\*!|--|\#)/', $statement)) {
            return false;
        }

        if (preg_match('/^SET\s+(@@|NAMES|CHARACTER_SET_CLIENT|CHARACTER_SET_CONNECTION|CHARACTER_SET_RESULTS|SESSION|GLOBAL)\s/i', $statement)) {
            return false;
        }

        if (preg_match('/^\s*$/', $statement)) {
            return false;
        }

        if (preg_match('/^DELIMITER\s/i', $statement)) {
            return false;
        }

        if (preg_match('/^USE\s+/i', $statement)) {
            return false;
        }

        $upperStatement = strtoupper($statement);
        $blockedStarts = [
            'DELIMITER ',
            'USE ',
            '/*!',
            '-- ',
            '# ',
            'SET @@',
            'SET NAMES',
            'SET CHARACTER_SET_CLIENT',
            'SET CHARACTER_SET_CONNECTION', 
            'SET CHARACTER_SET_RESULTS',
            'SET SESSION',
            'SET GLOBAL'
        ];
        
        foreach ($blockedStarts as $blocked) {
            if (strpos($upperStatement, $blocked) === 0) {
                return false;
            }
        }

        return true;
    }

    protected function splitLargeInsertStatement(string $statement): array
    {
        if (!preg_match('/^INSERT\s+INTO\s+`?(\w+)`?\s*\([^)]+\)\s+VALUES\s*(.+)$/is', $statement, $matches)) {
            return [$statement];
        }
        
        $tableName = $matches[1];
        $columns = '';
        $valuesSection = $matches[2];

        if (preg_match('/^INSERT\s+INTO\s+`?\w+`?\s*(\([^)]+\))\s+VALUES/is', $statement, $columnMatches)) {
            $columns = $columnMatches[1];
        }

        $valueRows = [];
        $currentRow = '';
        $parenthesesLevel = 0;
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($valuesSection); $i++) {
            $char = $valuesSection[$i];
            $currentRow .= $char;

            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar) {
                if ($i === 0 || $valuesSection[$i - 1] !== '\\') {
                    $inString = false;
                    $stringChar = '';
                }
            }

            if (!$inString) {
                if ($char === '(') {
                    $parenthesesLevel++;
                } elseif ($char === ')') {
                    $parenthesesLevel--;

                    if ($parenthesesLevel === 0) {
                        $nextPos = $i + 1;
                        while ($nextPos < strlen($valuesSection) && in_array($valuesSection[$nextPos], [' ', "\t", "\n", "\r"])) {
                            $nextPos++;
                        }
                        
                        if ($nextPos >= strlen($valuesSection) || $valuesSection[$nextPos] === ',') {
                            $row = trim($currentRow);
                            if ($nextPos < strlen($valuesSection) && $valuesSection[$nextPos] === ',') {
                                $row = rtrim($row, ',');
                                $i = $nextPos; 
                            }
                            $valueRows[] = $row;
                            $currentRow = '';
                        }
                    }
                }
            }
        }

        if (!empty(trim($currentRow))) {
            $valueRows[] = trim($currentRow);
        }

        $chunks = [];
        $currentChunk = [];
        $currentChunkSize = 0;
        $maxChunkSize = 512 * 1024;
        
        foreach ($valueRows as $row) {
            $rowSize = strlen($row);

            if ($currentChunkSize + $rowSize > $maxChunkSize && !empty($currentChunk)) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $currentChunkSize = 0;
            }
            
            $currentChunk[] = $row;
            $currentChunkSize += $rowSize;
        }

        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        $splitStatements = [];
        foreach ($chunks as $chunk) {
            $insertStatement = "INSERT INTO `{$tableName}` {$columns} VALUES " . implode(',', $chunk);
            $splitStatements[] = $insertStatement;
        }
        
        return $splitStatements;
    }
}