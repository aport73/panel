<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Security;

use Exception;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Doctrine\DBAL\Platforms\MySQLPlatform;

trait SqlParserValidator
{
    protected function validateSqlWithParser(string $sql, string $authorizedDb): void
    {
        try {
            $normalizedSql = $this->normalizeSqlForValidation($sql);
            $platform = new MySQLPlatform();
            $this->performEnhancedSqlAnalysis($normalizedSql, $authorizedDb);
            $this->validateWithContextualRules($normalizedSql, $authorizedDb);
            $securityScore = $this->calculateSecurityScore($normalizedSql, $authorizedDb);
            if ($securityScore['risk_level'] === 'HIGH') {
                throw new Exception('Query blocked: High security risk detected - ' . $securityScore['reason']);
            }
            
        } catch (Exception $e) {
            logger()->warning('SQL query blocked by enhanced security validation', [
                'database' => $authorizedDb,
                'error' => $e->getMessage(),
                'sql_preview' => substr($sql, 0, 100),
                'sql_length' => strlen($sql),
                'validation_stage' => $this->getValidationStage($e->getMessage()),
                'user_id' => auth()->id() ?? 'unknown',
                'ip_address' => request()->ip() ?? 'unknown',
                'timestamp' => now()->toISOString()
            ]);
            throw $e;
        }
    }

    protected function normalizeSqlForValidation(string $sql): string
    {
        $sql = preg_replace('/\/\*[\s\S]*?\*\//', '', $sql);
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/#.*$/m', '', $sql);
        $sql = preg_replace('/\s+/', ' ', $sql);
        $sql = trim($sql);
        $sql = rtrim($sql, ';');
        
        return $sql;
    }

    protected function performEnhancedSqlAnalysis(string $sql, string $authorizedDb): void
    {
        $nestedQueryCount = substr_count(strtoupper($sql), 'SELECT');
        if ($nestedQueryCount > 50) {
            throw new Exception('Query blocked: Excessive nested queries detected');
        }

        $suspiciousFunctions = [
            'EXTRACTVALUE', 'UPDATEXML', 'XMLEXTRACTVALUE', 'EXP', 'POW', 'FLOOR',
            'RAND', 'ROW_COUNT', 'FOUND_ROWS', 'LAST_INSERT_ID'
        ];
        
        foreach ($suspiciousFunctions as $func) {
            if (stripos($sql, $func . '(') !== false) {
                if (!$this->isFunctionAllowedInContext($func, $sql, $authorizedDb)) {
                    throw new Exception("Query blocked: Suspicious function '{$func}' detected");
                }
            }
        }

        if (preg_match('/\\\\[xuU][0-9a-fA-F]+/', $sql)) {
            throw new Exception('Query blocked: Encoding bypass attempt detected');
        }

        if (preg_match('/0x[0-9a-fA-F]+/', $sql) && strlen($sql) > 200) {
            throw new Exception('Query blocked: Suspicious binary data detected');
        }
    }

    protected function isFunctionAllowedInContext(string $function, string $sql, string $authorizedDb): bool
    {
        $allowedContexts = [
            'LAST_INSERT_ID' => ['/INSERT\s+INTO\s+`?' . preg_quote($authorizedDb, '/') . '`?\./i'],
            'ROW_COUNT' => ['/UPDATE\s+`?' . preg_quote($authorizedDb, '/') . '`?\./i', '/DELETE\s+FROM\s+`?' . preg_quote($authorizedDb, '/') . '`?\./i'],
            'FOUND_ROWS' => ['/SELECT\s+SQL_CALC_FOUND_ROWS/i']
        ];
        
        if (!isset($allowedContexts[$function])) {
            return false;
        }
        
        foreach ($allowedContexts[$function] as $pattern) {
            if (preg_match($pattern, $sql)) {
                return true;
            }
        }
        
        return false;
    }

    protected function calculateSecurityScore(string $sql, string $authorizedDb): array
    {
        $score = 0;
        $reasons = [];
        $complexity = $this->analyzeQueryComplexity($sql);
        $score += min($complexity, 100);

        if (stripos($sql, 'UNION') !== false) {
            $score += 30;
            $reasons[] = 'contains UNION operations';
        }
        
        if (preg_match_all('/\bSELECT\b/i', $sql) > 3) {
            $score += 20;
            $reasons[] = 'multiple SELECT statements';
        }
        
        if (stripos($sql, 'information_schema') !== false) {
            $score += 15;
            $reasons[] = 'accesses information_schema';
        }
        
        if (preg_match('/\b(?:CONCAT|CHAR|ASCII|ORD|HEX|UNHEX)\s*\(/i', $sql)) {
            $score += 10;
            $reasons[] = 'uses string manipulation functions';
        }
        
        if (strlen($sql) > 1000) {
            $score += 15;
            $reasons[] = 'query length exceeds 1000 characters';
        }

        $riskLevel = 'LOW';
        if ($score > 500) {
            $riskLevel = 'HIGH';
        } elseif ($score > 300) {
            $riskLevel = 'MEDIUM';
        }
        
        return [
            'complexity' => $score,
            'risk_level' => $riskLevel,
            'reason' => implode(', ', $reasons),
            'factors' => $reasons
        ];
    }

    protected function getValidationStage(string $errorMessage): string
    {
        if (strpos($errorMessage, 'syntax') !== false) return 'syntax_validation';
        if (strpos($errorMessage, 'complexity') !== false) return 'complexity_analysis';
        if (strpos($errorMessage, 'security policy') !== false) return 'contextual_rules';
        if (strpos($errorMessage, 'security risk') !== false) return 'security_scoring';
        if (strpos($errorMessage, 'function') !== false) return 'function_analysis';
        return 'unknown';
    }

    protected function getQueryType(string $sql): string
    {
        $sql = trim(strtoupper($sql));
        
        if (strpos($sql, 'SELECT') === 0) {
            if (strpos($sql, 'INFORMATION_SCHEMA') !== false) {
                return 'INFORMATION_SCHEMA_SELECT';
            }
            return 'SELECT';
        }
        
        if (strpos($sql, 'INSERT') === 0) return 'INSERT';
        if (strpos($sql, 'UPDATE') === 0) return 'UPDATE';
        if (strpos($sql, 'DELETE') === 0) return 'DELETE';
        if (strpos($sql, 'TRUNCATE') === 0) return 'TRUNCATE';
        if (strpos($sql, 'SHOW') === 0) return 'SHOW';
        if (strpos($sql, 'DESCRIBE') === 0 || strpos($sql, 'DESC') === 0) return 'DESCRIBE';
        if (strpos($sql, 'EXPLAIN') === 0) return 'EXPLAIN';
        
        return 'UNKNOWN';
    }

    protected function performSemanticAnalysis($statement, string $authorizedDb): void
    {
        $statementClass = get_class($statement);
        $dangerousStatements = [
            'Doctrine\\DBAL\\Query\\Expression\\CreateDatabaseExpression',
            'Doctrine\\DBAL\\Query\\Expression\\DropDatabaseExpression',
            'Doctrine\\DBAL\\Query\\Expression\\CreateUserExpression',
            'Doctrine\\DBAL\\Query\\Expression\\DropUserExpression',
            'Doctrine\\DBAL\\Query\\Expression\\GrantExpression',
            'Doctrine\\DBAL\\Query\\Expression\\RevokeExpression',
        ];
        
        foreach ($dangerousStatements as $dangerousType) {
            if (strpos($statementClass, $dangerousType) !== false) {
                throw new Exception('Dangerous SQL statement type detected');
            }
        }

        $this->performEnhancedRegexValidation($statement->getSQL() ?? '', $authorizedDb);
    }

    protected function performEnhancedRegexValidation(string $sql, string $authorizedDb): void
    {
        $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));
        $normalizedSql = preg_replace('/\/\*.*?\*\//s', '', $normalizedSql);
        $normalizedSql = preg_replace('/--.*$/m', '', $normalizedSql);
        $enhancedDangerousPatterns = [
            '/(?:LOAD_FILE|INTO\s+(?:OUT|DUMP)FILE|LOAD\s+DATA)/iu',
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',
        ];
        
        foreach ($enhancedDangerousPatterns as $pattern) {
            if (preg_match($pattern, $normalizedSql)) {
                throw new Exception('Enhanced security validation failed: Dangerous SQL pattern detected');
            }
        }
    }

    protected function analyzeQueryComplexity(string $sql): int

    
    {
        $complexity = 0;
        $complexity += substr_count(strtoupper($sql), 'UNION') * 10;
        $complexity += substr_count(strtoupper($sql), 'SUBQUERY') * 8;
        $complexity += substr_count(strtoupper($sql), 'JOIN') * 5;
        $complexity += substr_count(strtoupper($sql), 'SELECT') * 3;
        $complexity += substr_count(strtoupper($sql), 'WHERE') * 2;
        $complexity += substr_count(strtoupper($sql), 'OR') * 2;
        $complexity += substr_count(strtoupper($sql), 'AND') * 1;
        if (strlen($sql) > 1000) {
            $complexity += (strlen($sql) - 1000) / 100;
        }

        $complexity += substr_count($sql, '(') * 2;
        
        return (int) $complexity;
    }
}