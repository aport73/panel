<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Security;

use Exception;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Doctrine\DBAL\Platforms\MySQLPlatform;

trait SqlSecurityValidator
{
    protected array $securityConfig = [
        'allow_information_schema_reads' => true,
        'allow_table_maintenance' => true,
        'require_where_clause_for_updates' => false,
        'require_where_clause_for_deletes' => false,
        'max_query_complexity' => 200,
        'allowed_functions' => ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN', 'CONCAT', 'SUBSTRING', 'GROUP_CONCAT', 'COALESCE', 'IFNULL'],
    ];

    protected function validateWithContextualRules(string $sql, string $authorizedDb): void
    {
        $normalizedSql = $this->performSecureNormalization($sql);
        $this->detectBypassAttempts($normalizedSql, $authorizedDb);
        if ($this->isSafeInformationSchemaQuery($normalizedSql, $authorizedDb)) {
            return; 
        }

        if ($this->isSafeAdministrativeQuery($normalizedSql, $authorizedDb)) {
            return; 
        }

        if ($this->isStandardCrudOperation($normalizedSql, $authorizedDb)) {
            return;
        }

        throw new Exception('Query not permitted by security policy');
    }

    protected function performSecureNormalization(string $sql): string
    {
        $sql = preg_replace('/\/\*[\s\S]*?\*\//', '', $sql);
        $sql = preg_replace('/--[^\r\n]*/', '', $sql);
        $sql = preg_replace('/#[^\r\n]*/', '', $sql);
        $sql = preg_replace('/\/\*![0-9]*[\s\S]*?\*\//', '', $sql);
        $sql = preg_replace('/[\t\n\r\f\v]+/', ' ', $sql);
        $sql = preg_replace('/\s+/', ' ', $sql);
        $sql = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $sql);
        
        return trim($sql);
    }

    protected function detectBypassAttempts(string $sql, string $authorizedDb): void
    {
        if (preg_match('/%[0-9a-fA-F]{2}/', $sql)) {
            throw new Exception('Query blocked: URL encoding detected');
        }

        if (preg_match('/\\\\u[0-9a-fA-F]{4}/', $sql)) {
            throw new Exception('Query blocked: Unicode encoding bypass detected');
        }

        if (preg_match('/\|\||\+\s*[\'"][^\'"]/', $sql)) {
            throw new Exception('Query blocked: String concatenation bypass detected');
        }

        $suspiciousPatterns = [
            '/[sS][eE][lL][eE][cC][tT].*[fF][rR][oO][mM].*[mM][yY][sS][qQ][lL]/',
            '/[uU][nN][iI][oO][nN].*[sS][eE][lL][eE][cC][tT]/',
            '/[iI][nN][fF][oO][rR][mM][aA][tT][iI][oO][nN]_[sS][cC][hH][eE][mM][aA]/',
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                throw new Exception('Query blocked: Suspicious keyword pattern detected');
            }
        }

        if (preg_match('/;\s*(?:DROP|CREATE|ALTER|INSERT|UPDATE|DELETE|GRANT|REVOKE)/i', $sql)) {
            throw new Exception('Query blocked: Stacked query attempt detected');
        }

        if (preg_match('/(?:SLEEP|BENCHMARK|GET_LOCK)\s*\(\s*[0-9]+/i', $sql)) {
            throw new Exception('Query blocked: Time-based attack pattern detected');
        }

        if (preg_match('/(?:EXTRACTVALUE|UPDATEXML|GEOMETRYCOLLECTION|POLYGON|LINESTRING)\s*\(/i', $sql)) {
            throw new Exception('Query blocked: Error-based injection pattern detected');
        }

        $parenCount = substr_count($sql, '(') + substr_count($sql, ')');
        if ($parenCount > 20) {
            throw new Exception('Query blocked: Excessive parentheses detected');
        }

        if (preg_match('/(?:CASE|IF)\s*\(.*(?:SELECT|@@|VERSION|USER|DATABASE)/i', $sql)) {
            throw new Exception('Query blocked: Conditional injection pattern detected');
        }
    }

    protected function isSafeInformationSchemaQuery(string $sql, string $authorizedDb): bool
    {
        $safePatterns = [
            '/^SELECT\s+.*\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+$/i',
            '/^SELECT\s+.*\s+FROM\s+information_schema\.columns\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+$/i',
            '/^SELECT\s+.*\s+FROM\s+information_schema\.key_column_usage\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+$/i',
            '/^SELECT\s+.*\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+.*\s+GROUP\s+BY/i',
            '/^SELECT\s+.*\s+FROM\s+information_schema\.schemata\s+WHERE\s+schema_name\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+$/i',
            '/^SELECT\s+.*\s+FROM\s*\(\s*SELECT\s+.*\s+FROM\s+information_schema\.schemata\s+WHERE\s+schema_name\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+.*\)\s+AS\s+\w+$/i',
            '/^SELECT\s+.*CONCAT\s*\(.*[\'"]TRUNCATE\s+TABLE.*\).*\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+/i',
            '/^SELECT\s+.*CONCAT\s*\(.*[\'"]INSERT\s+INTO.*\).*\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+/i',
            '/^SELECT\s+.*CONCAT\s*\(.*[\'"]UPDATE.*\).*\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+/i',
            '/^SELECT\s+.*CONCAT\s*\(.*[\'"]DELETE\s+FROM.*\).*\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+/i',
            '/^SELECT\s+.*CONCAT\s*\(.*[\'"]ALTER\s+TABLE.*\).*\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+/i',
            '/^SELECT\s+.*CONCAT\s*\(.*[\'"]DROP\s+TABLE.*\).*\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+/i',
            '/^SELECT\s+.*CONCAT\s*\(.*[\'"]CREATE\s+TABLE.*\).*\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+/i',
            '/^SELECT\s+.*table_name.*CONCAT\s*\(.*\).*\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+/i',
            '/^SELECT\s+.*\s+FROM\s+information_schema\.tables\s+WHERE\s+table_schema\s*=\s*[\'"]+' . preg_quote($authorizedDb, '/') . '[\'"]+.*\s+(ORDER\s+BY|LIMIT|GROUP\s+BY)/i',
        ];
        
        foreach ($safePatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                if ($this->validateDatabaseAccess($sql, $authorizedDb)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    protected function validateDatabaseAccess(string $sql, string $authorizedDb): bool
    {
        if (preg_match_all('/(?:schema_name|table_schema)\s*=\s*[\'"]+(^[^\'"]+)[\'"]/', $sql, $matches)) {
            foreach ($matches[1] as $referencedDb) {
                if (strtolower($referencedDb) !== strtolower($authorizedDb)) {
                    return false;
                }
            }
        }

        $dangerousPatterns = [
            '/\b(mysql\.|performance_schema\.|sys\.)\b/i',
            '/information_schema\.(user_privileges|schema_privileges|table_privileges)/i',
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return false;
            }
        }
        
        return true;
    }

    protected function isSafeAdministrativeQuery(string $sql, string $authorizedDb): bool
    {
        $safeAdminPatterns = [
            '/^SHOW\s+TABLES\s*;?$/i',
            '/^SHOW\s+TABLES\s+FROM\s+`?' . preg_quote($authorizedDb, '/') . '`?\s*;?$/i',
            '/^SHOW\s+COLUMNS\s+FROM\s+\w+\s*;?$/i',
            '/^SHOW\s+COLUMNS\s+FROM\s+`?' . preg_quote($authorizedDb, '/') . '`?\.\w+\s*;?$/i',
            '/^SHOW\s+INDEX\s+FROM\s+\w+\s*;?$/i',
            '/^SHOW\s+INDEX\s+FROM\s+`?' . preg_quote($authorizedDb, '/') . '`?\.\w+\s*;?$/i',
            '/^SHOW\s+CREATE\s+TABLE\s+\w+\s*;?$/i',
            '/^SHOW\s+CREATE\s+TABLE\s+`?' . preg_quote($authorizedDb, '/') . '`?\.\w+\s*;?$/i',
            '/^DESCRIBE\s+\w+\s*;?$/i',
            '/^DESCRIBE\s+`?' . preg_quote($authorizedDb, '/') . '`?\.\w+\s*;?$/i',
            '/^DESC\s+\w+\s*;?$/i',
            '/^DESC\s+`?' . preg_quote($authorizedDb, '/') . '`?\.\w+\s*;?$/i',
            '/^REPAIR\s+TABLE\s+(`?' . preg_quote($authorizedDb, '/') . '`?\.)?\w+\s*;?$/i',
        ];
        
        foreach ($safeAdminPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                return true;
            }
        }
        
        return false;
    }

    protected function isStandardCrudOperation(string $sql, string $authorizedDb): bool
    {
        $sqlUpper = strtoupper($sql);
        if (strpos($sqlUpper, 'SELECT') === 0) {
            return $this->validateSelectOperation($sql, $authorizedDb);
        }

        if (strpos($sqlUpper, 'INSERT') === 0) {
            return $this->validateInsertOperation($sql, $authorizedDb);
        }

        if (strpos($sqlUpper, 'UPDATE') === 0) {
            return $this->validateUpdateOperation($sql, $authorizedDb);
        }

        if (strpos($sqlUpper, 'DELETE') === 0) {
            return $this->validateDeleteOperation($sql, $authorizedDb);
        }

        if (strpos($sqlUpper, 'CREATE TABLE') === 0) {
            return $this->validateTableOperation($sql, $authorizedDb);
        }
        
        if (strpos($sqlUpper, 'ALTER TABLE') === 0) {
            return $this->validateTableOperation($sql, $authorizedDb);
        }
        
        if (strpos($sqlUpper, 'DROP TABLE') === 0) {
            return $this->validateTableOperation($sql, $authorizedDb);
        }

        if (strpos($sqlUpper, 'TRUNCATE TABLE') === 0) {
            return $this->validateTableOperation($sql, $authorizedDb);
        }
        
        if (strpos($sqlUpper, 'CREATE INDEX') === 0 || strpos($sqlUpper, 'DROP INDEX') === 0) {
            return $this->validateTableOperation($sql, $authorizedDb);
        }
        
        return false;
    }

    protected function validateSelectOperation(string $sql, string $authorizedDb): bool
    {
        if (preg_match('/\b(mysql|performance_schema|sys)\./i', $sql)) {
            return false;
        }

        if (preg_match('/\binformation_schema\./i', $sql)) {
            return $this->isSafeInformationSchemaQuery($sql, $authorizedDb);
        }

        if (preg_match('/\bFROM\s+(\w+)\./i', $sql, $matches)) {
            return strtolower($matches[1]) === strtolower($authorizedDb);
        }

        return true;
    }

    protected function validateInsertOperation(string $sql, string $authorizedDb): bool
    {
        if (preg_match('/\bINSERT\s+INTO\s+(\w+)\./i', $sql, $matches)) {
            $dbName = $matches[1];
            if (strtolower($dbName) !== strtolower($authorizedDb)) {
                return false;
            }
        }

        return true;
    }

    protected function validateUpdateOperation(string $sql, string $authorizedDb): bool
    {
        if (preg_match('/\bUPDATE\s+(\w+)\./i', $sql, $matches)) {
            $dbName = $matches[1];
            if (strtolower($dbName) !== strtolower($authorizedDb)) {
                return false;
            }
        }
        
        return true;
    }

    protected function validateDeleteOperation(string $sql, string $authorizedDb): bool
    {
         if (preg_match('/\bDELETE\s+FROM\s+(\w+)\./i', $sql, $matches)) {
            $dbName = $matches[1];
            if (strtolower($dbName) !== strtolower($authorizedDb)) {
                return false;
            }
        }
        
        return true;
    }

    protected function validateTableOperation(string $sql, string $authorizedDb): bool
    {
        if (preg_match('/\b(?:TABLE|INDEX)\s+([\w`]+)\.([\w`]+)/i', $sql, $matches)) {
            $dbName = trim($matches[1], '`');
            return strtolower($dbName) === strtolower($authorizedDb);
        }

        return true;
    }
}