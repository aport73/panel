<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Validation;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Exceptions\Service\Database\DatabaseImportException;

trait ContentValidator
{
    protected function analyzeDumpType(string $content, array $statements): array
    {
        $analysis = [
            'generator' => 'unknown',
            'version' => 'unknown',
            'charset' => 'unknown',
            'has_structure' => false,
            'has_data' => false,
            'has_triggers' => false,
            'has_procedures' => false,
            'has_views' => false,
            'estimated_tables' => 0,
            'estimated_records' => 0,
        ];
        $headerInfo = $this->analyzeDumpHeader($content);
        $analysis = array_merge($analysis, $headerInfo);
        $statementTypes = $this->analyzeStatementTypes($statements);
        $analysis['has_structure'] = $statementTypes['create_table'] > 0 || $statementTypes['create_index'] > 0;
        $analysis['has_data'] = $statementTypes['insert'] > 0;
        $analysis['has_triggers'] = $statementTypes['create_trigger'] > 0;
        $analysis['has_procedures'] = $statementTypes['create_procedure'] > 0 || $statementTypes['create_function'] > 0;
        $analysis['has_views'] = $statementTypes['create_view'] > 0;
        $analysis['estimated_tables'] = $statementTypes['create_table'];
        $analysis['estimated_records'] = $statementTypes['insert'];
        $analysis['dump_type'] = $this->determineDumpType($analysis, $statementTypes);
        $analysis['characteristics'] = $this->getDumpCharacteristics($analysis, $statementTypes, $content);
        
        return $analysis;
    }

    protected function analyzeDumpHeader(string $content): array
    {
        $info = [
            'generator' => 'unknown',
            'version' => 'unknown',
            'charset' => 'unknown',
            'mysql_version' => 'unknown',
            'dump_date' => 'unknown',
        ];

        $header = substr($content, 0, 2000);

        if (preg_match('/-- MySQL dump (\d+\.\d+)/i', $header, $matches)) {
            $info['generator'] = 'mysqldump';
            $info['version'] = $matches[1];
        }

        if (preg_match('/-- phpMyAdmin SQL Dump/i', $header)) {
            $info['generator'] = 'phpMyAdmin';
            if (preg_match('/-- version (\d+\.\d+\.\d+)/i', $header, $matches)) {
                $info['version'] = $matches[1];
            }
        }

        if (preg_match('/-- Adminer (\d+\.\d+\.\d+)/i', $header, $matches)) {
            $info['generator'] = 'Adminer';
            $info['version'] = $matches[1];
        }

        if (preg_match('/-- HeidiSQL version:\s*(\d+\.\d+)/i', $header, $matches)) {
            $info['generator'] = 'HeidiSQL';
            $info['version'] = $matches[1];
        }

        if (preg_match('/-- MySQL Workbench/i', $header)) {
            $info['generator'] = 'MySQL Workbench';
        }

        if (preg_match('/SET NAMES ([a-zA-Z0-9_]+)/i', $header, $matches)) {
            $info['charset'] = $matches[1];
        }

        if (preg_match('/-- Server version:\s*(\d+\.\d+\.\d+)/i', $header, $matches)) {
            $info['mysql_version'] = $matches[1];
        }

        if (preg_match('/-- Dump completed on (\d{4}-\d{2}-\d{2})/i', $header, $matches)) {
            $info['dump_date'] = $matches[1];
        } elseif (preg_match('/-- Generated on: (.+)/i', $header, $matches)) {
            $info['dump_date'] = trim($matches[1]);
        }
        
        return $info;
    }

    protected function analyzeStatementTypes(array $statements): array
    {
        $types = [
            'create_table' => 0,
            'create_index' => 0,
            'create_view' => 0,
            'create_trigger' => 0,
            'create_procedure' => 0,
            'create_function' => 0,
            'insert' => 0,
            'update' => 0,
            'delete' => 0,
            'alter_table' => 0,
            'drop_table' => 0,
            'set_statements' => 0,
            'use_statements' => 0,
            'lock_tables' => 0,
            'unlock_tables' => 0,
            'other' => 0,
        ];
        
        foreach ($statements as $statement) {
            $statement = trim(strtoupper($statement));
            
            if (empty($statement)) {
                continue;
            }

            if (strpos($statement, 'CREATE TABLE') === 0) {
                $types['create_table']++;
            } elseif (strpos($statement, 'CREATE INDEX') === 0 || strpos($statement, 'CREATE UNIQUE INDEX') === 0) {
                $types['create_index']++;
            } elseif (strpos($statement, 'CREATE VIEW') === 0) {
                $types['create_view']++;
            } elseif (strpos($statement, 'CREATE TRIGGER') === 0) {
                $types['create_trigger']++;
            } elseif (strpos($statement, 'CREATE PROCEDURE') === 0 || strpos($statement, 'CREATE DEFINER') === 0 && strpos($statement, 'PROCEDURE') !== false) {
                $types['create_procedure']++;
            } elseif (strpos($statement, 'CREATE FUNCTION') === 0 || strpos($statement, 'CREATE DEFINER') === 0 && strpos($statement, 'FUNCTION') !== false) {
                $types['create_function']++;
            } elseif (strpos($statement, 'INSERT INTO') === 0) {
                $types['insert']++;
            } elseif (strpos($statement, 'UPDATE') === 0) {
                $types['update']++;
            } elseif (strpos($statement, 'DELETE FROM') === 0) {
                $types['delete']++;
            } elseif (strpos($statement, 'ALTER TABLE') === 0) {
                $types['alter_table']++;
            } elseif (strpos($statement, 'DROP TABLE') === 0) {
                $types['drop_table']++;
            } elseif (strpos($statement, 'SET') === 0) {
                $types['set_statements']++;
            } elseif (strpos($statement, 'USE') === 0) {
                $types['use_statements']++;
            } elseif (strpos($statement, 'LOCK TABLES') === 0) {
                $types['lock_tables']++;
            } elseif (strpos($statement, 'UNLOCK TABLES') === 0) {
                $types['unlock_tables']++;
            } else {
                $types['other']++;
            }
        }
        
        return $types;
    }

    protected function determineDumpType(array $analysis, array $statementTypes): string
    {
        if ($analysis['has_structure'] && $analysis['has_data'] && 
            ($statementTypes['set_statements'] > 0 || $statementTypes['lock_tables'] > 0)) {
            return 'complete_dump';
        }

        if ($analysis['has_structure'] && !$analysis['has_data']) {
            return 'structure_only';
        }

        if (!$analysis['has_structure'] && $analysis['has_data']) {
            return 'data_only';
        }

        if ($analysis['has_structure'] && $analysis['has_data']) {
            return 'mixed_dump';
        }

        if ($statementTypes['update'] > 0 || $statementTypes['delete'] > 0 || $statementTypes['alter_table'] > 0) {
            return 'partial_dump';
        }

        return 'unknown';
    }

    protected function getDumpCharacteristics(array $analysis, array $statementTypes, string $content): array
    {
        $characteristics = [];
        $characteristics['content_size'] = strlen($content);
        $characteristics['content_size_mb'] = round(strlen($content) / 1024 / 1024, 2);
        $characteristics['total_statements'] = array_sum($statementTypes);
        $characteristics['statement_distribution'] = $statementTypes;
        $characteristics['has_foreign_keys'] = strpos($content, 'FOREIGN KEY') !== false;
        $characteristics['has_constraints'] = strpos($content, 'CONSTRAINT') !== false;
        $characteristics['has_auto_increment'] = strpos($content, 'AUTO_INCREMENT') !== false;
        $characteristics['has_fulltext_indexes'] = strpos($content, 'FULLTEXT') !== false;
        $characteristics['has_user_management'] = preg_match('/\b(?:CREATE|DROP|ALTER)\s+USER\b/i', $content);
        $characteristics['has_grants'] = preg_match('/\b(?:GRANT|REVOKE)\b/i', $content);
        $characteristics['has_database_operations'] = preg_match('/\b(?:CREATE|DROP|ALTER)\s+DATABASE\b/i', $content);

        if ($analysis['has_data']) {
            $insertMatches = preg_match_all('/INSERT INTO/i', $content);
            $characteristics['estimated_insert_statements'] = $insertMatches;
            $characteristics['has_large_inserts'] = preg_match('/INSERT INTO .{1000,}/i', $content);
            $characteristics['has_blob_data'] = preg_match('/\b(?:BLOB|LONGBLOB|MEDIUMBLOB|TINYBLOB)\b/i', $content);
        }

        $characteristics['mysql_specific_features'] = [];
        if (strpos($content, 'ENGINE=') !== false) {
            $characteristics['mysql_specific_features'][] = 'storage_engines';
        }
        if (strpos($content, 'CHARSET=') !== false) {
            $characteristics['mysql_specific_features'][] = 'character_sets';
        }
        if (strpos($content, 'COLLATE=') !== false) {
            $characteristics['mysql_specific_features'][] = 'collations';
        }
        
        return $characteristics;
    }

    protected function validateFileContent(string $content, UploadedFile $file): void
    {
        $binarySignatures = [
            'PDF' => '%PDF',
            'ZIP' => 'PK',
            'RAR' => 'Rar!',
            'EXE' => 'MZ',
            'PNG' => "\x89PNG",
            'JPEG' => "\xFF\xD8\xFF",
            'GIF' => 'GIF8',
            'BMP' => 'BM',
            'TIFF' => 'II*',
        ];
        
        foreach ($binarySignatures as $type => $signature) {
            if (strpos($content, $signature) === 0) {
                throw new DatabaseImportException("File appears to be a {$type} file, not a SQL dump");
            }
        }

        if (strlen($content) < 10) {
            throw new DatabaseImportException('File content is too short to be a valid SQL dump');
        }

        $this->validateSqlContent($content);
    }

    protected function validateSqlContent(string $content): void
    {
        $normalizedContent = preg_replace('/\/\*[\s\S]*?\*\//', '', $content);
        $normalizedContent = preg_replace('/--.*$/m', '', $normalizedContent);
        $normalizedContent = preg_replace('/#.*$/m', '', $normalizedContent);
        $normalizedContent = preg_replace('/\s+/', ' ', $normalizedContent);
        $sqlKeywords = [
            'CREATE', 'INSERT', 'UPDATE', 'DELETE', 'SELECT', 'ALTER', 'DROP',
            'TABLE', 'DATABASE', 'INDEX', 'VIEW', 'PROCEDURE', 'FUNCTION',
            'PRIMARY KEY', 'FOREIGN KEY', 'UNIQUE', 'NOT NULL', 'DEFAULT',
            'VARCHAR', 'INT', 'BIGINT', 'TEXT', 'DATETIME', 'TIMESTAMP'
        ];
        
        $keywordCount = 0;
        $upperContent = strtoupper($normalizedContent);
        
        foreach ($sqlKeywords as $keyword) {
            if (strpos($upperContent, $keyword) !== false) {
                $keywordCount++;
            }
        }

        if ($keywordCount < 1) {
            throw new DatabaseImportException('File does not appear to contain valid SQL content');
        }

        $parenthesesBalance = substr_count($content, '(') - substr_count($content, ')');
        if (abs($parenthesesBalance) > 10) { 
            logger()->warning('Potential syntax issue: unbalanced parentheses', [
                'balance' => $parenthesesBalance,
                'open_parens' => substr_count($content, '('),
                'close_parens' => substr_count($content, ')')
            ]);
        }

        $lines = explode("\n", $content);
        $maxLineLength = 0;
        $longLineCount = 0;
        
        foreach ($lines as $line) {
            $lineLength = strlen($line);
            $maxLineLength = max($maxLineLength, $lineLength);
            
            if ($lineLength > 100000) {
                $longLineCount++;
            }
        }
        
        if ($longLineCount > 100) {
            logger()->warning('File contains many extremely long lines', [
                'long_line_count' => $longLineCount,
                'max_line_length' => $maxLineLength
            ]);
        }

        $statementCount = substr_count($content, ';');
        $contentLength = strlen($content);
        
        if ($statementCount > 0) {
            $avgStatementLength = $contentLength / $statementCount;

            if ($avgStatementLength > 50000) { 
                logger()->warning('Statements appear unusually long', [
                    'avg_statement_length' => round($avgStatementLength),
                    'statement_count' => $statementCount,
                    'content_length' => $contentLength
                ]);
            }
        }
    }

    protected function detectMaliciousContent(string $content): void
    {
        $dangerousPatterns = [
            '/\bLOAD_FILE\s*\(/i',
            '/\bINTO\s+(?:OUT|DUMP)FILE\b/i',
            '/<\?php/i',
            '/<script/i',
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new DatabaseImportException('File contains operations that could access server files');
            }
        }
    }

    protected function validateSqlStructure(string $content): void
    {
        $statements = $this->extractSqlStatements($content);
        
        if (empty($statements)) {
            throw new DatabaseImportException('No valid SQL statements found in file');
        }
        $sampleSize = min(100, count($statements));
        $sampleStatements = array_slice($statements, 0, $sampleSize);
        
        $validStatements = 0;
        $invalidStatements = 0;
        
        foreach ($sampleStatements as $statement) {
            try {
                $this->validateSingleStatement($statement);
                $validStatements++;
            } catch (Exception $e) {
                $invalidStatements++;
            }
        }

        $validPercentage = ($validStatements / $sampleSize) * 100;
        if ($validPercentage < 50) {
            throw new DatabaseImportException("File contains too many invalid SQL statements ({$validPercentage}% valid)");
        }
        
    }

    protected function extractSqlStatements(string $content): array
    {
        $content = preg_replace('/\/\*[\s\S]*?\*\//', '', $content);
        $content = preg_replace('/--.*$/m', '', $content);
        $content = preg_replace('/#.*$/m', '', $content);
        $statements = [];
        $currentStatement = '';
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($content); $i++) {
            $char = $content[$i];

            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar) {
                if ($i === 0 || $content[$i - 1] !== '\\') {
                    $inString = false;
                    $stringChar = '';
                }
            }
            
            $currentStatement .= $char;
            if (!$inString && $char === ';') {
                $statement = trim($currentStatement);
                if (!empty($statement)) {
                    $statements[] = $statement;
                }
                $currentStatement = '';
            }
        }

        $statement = trim($currentStatement);
        if (!empty($statement)) {
            $statements[] = $statement;
        }
        
        return $statements;
    }

    protected function validateSingleStatement(string $statement): void
    {
        $statement = trim($statement);
        
        if (empty($statement)) {
            throw new Exception('Empty statement');
        }

        $this->validateStatementSafety($statement);
        $openParens = substr_count($statement, '(');
        $closeParens = substr_count($statement, ')');
        if ($openParens !== $closeParens) {
            throw new Exception('Unbalanced parentheses in statement');
        }

        $singleQuotes = substr_count($statement, "'") - substr_count($statement, "\\'");
        $doubleQuotes = substr_count($statement, '"') - substr_count($statement, '\\"');
        
        if ($singleQuotes % 2 !== 0 || $doubleQuotes % 2 !== 0) {
            throw new Exception('Unbalanced quotes in statement');
        }

        if (strlen($statement) > 16777216) {
            throw new Exception('Statement too long');
        }
    }

    protected function validateStatementSafety(string $statement): void
    {
        $dangerousPatterns = [
            '/\bLOAD_FILE\s*\(/i',
            '/\bINTO\s+(?:OUT|DUMP)FILE\b/i',
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $statement)) {
                throw new Exception('Statement contains file operations that are not allowed');
            }
        }
    }
}