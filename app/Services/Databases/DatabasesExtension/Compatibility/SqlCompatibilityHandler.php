<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Compatibility;

use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\Services\Database\DatabaseImportException;




class SqlCompatibilityHandler
{
    private ?string $detectedDatabaseType = null;

    private const DEPRECATED_VARIABLES = [
        'innodb_log_file_size' => null, 
        'innodb_additional_mem_pool_size' => null, 
        'query_cache_size' => null, 
        'query_cache_type' => null, 
        'innodb_file_format' => null,
        'innodb_large_prefix' => null, 
        'innodb_file_per_table' => 'innodb_file_per_table', 
        'sql_mode' => 'sql_mode', 
    ];

    private const SYNTAX_PATTERNS = [
        '/ENGINE=MyISAM/i' => 'ENGINE=InnoDB',
        '/TYPE=MyISAM/i' => 'ENGINE=InnoDB',
        '/TYPE=InnoDB/i' => 'ENGINE=InnoDB',
        '/DEFAULT CHARSET=latin1/i' => 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        '/COLLATE latin1_swedish_ci/i' => 'COLLATE utf8mb4_unicode_ci',
        '/TIMESTAMP DEFAULT \'0000-00-00 00:00:00\'/i' => 'TIMESTAMP NULL DEFAULT NULL',
        '/DATE DEFAULT \'0000-00-00\'/i' => 'DATE NULL DEFAULT NULL',
        '/DATETIME DEFAULT \'0000-00-00 00:00:00\'/i' => 'DATETIME NULL DEFAULT NULL',
        '/utf8mb4_0900_ai_ci/i' => 'utf8mb4_unicode_ci',
        '/utf8_unicode_520_ci/i' => 'utf8_unicode_ci',
    ];

    private const POSTGRESQL_PATTERNS = [
        '/\b(\w+)\s+SERIAL\s+PRIMARY\s+KEY\b/i' => '$1 INT AUTO_INCREMENT PRIMARY KEY',
        '/\b(\w+)\s+BIGSERIAL\s+PRIMARY\s+KEY\b/i' => '$1 BIGINT AUTO_INCREMENT PRIMARY KEY',
        '/\b(\w+)\s+SMALLSERIAL\s+PRIMARY\s+KEY\b/i' => '$1 SMALLINT AUTO_INCREMENT PRIMARY KEY',
        '/\b(\w+)\s+TEXT\[\s*(\d*)\s*\]/i' => '$1 JSON -- Array converted',
        '/\b(\w+)\s+INTEGER\[\s*(\d*)\s*\]/i' => '$1 JSON -- Array converted',
        '/\b(\w+)\s+VARCHAR\((\d+)\)\[\s*(\d*)\s*\]/i' => '$1 JSON -- Array converted',
        '/\b(\w+)\s+NUMERIC\((\d+),(\d+)\)\[\s*(\d*)\s*\]/i' => '$1 JSON -- Array converted',
        '/\bSERIAL\b/i' => 'INT AUTO_INCREMENT',
        '/\bBIGSERIAL\b/i' => 'BIGINT AUTO_INCREMENT',
        '/\bSMALLSERIAL\b/i' => 'SMALLINT AUTO_INCREMENT',
        '/\bBOOLEAN\b/i' => 'TINYINT(1)',
        '/\bUUID\b/i' => 'CHAR(36)',
        '/\bBYTEA\b/i' => 'LONGBLOB',
        '/\bTIMESTAMPTZ\b/i' => 'TIMESTAMP',
        '/\bTIMETZ\b/i' => 'TIME',
        '/\bINET\b/i' => 'VARCHAR(45)', 
        '/\bCIDR\b/i' => 'VARCHAR(43)', 
        '/\bMACMDDR\b/i' => 'VARCHAR(17)', 
        '/\bJSON\b/i' => 'JSON',
        '/\bJSONB\b/i' => 'JSON', 
        '/CREATE\s+SEQUENCE\s+IF\s+NOT\s+EXISTS\s+(\w+)(?:\s+[^;]*)?;?/i' => '-- SEQUENCE $1 converted to AUTO_INCREMENT',
        '/CREATE\s+SEQUENCE\s+(\w+)(?:\s+[^;]*)?;?/i' => '-- SEQUENCE $1 converted to AUTO_INCREMENT',
        '/NEXTVAL\([\'"]?(\w+)[\'"]?\)/i' => 'NULL -- AUTO_INCREMENT handles this',
        '/CURRVAL\([\'"]?(\w+)[\'"]?\)/i' => 'LAST_INSERT_ID()',
        '/SETVAL\([\'"]?(\w+)[\'"]?,\s*\d+(?:\s*,\s*(?:true|false))?\)/i' => '-- SETVAL converted',
        '/\bGENERATE_SERIES\s*\(\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*(\d+))?\s*\)/i' => '(SELECT @row := @row + 1 as n FROM (SELECT 0 UNION ALL SELECT 1 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t1, (SELECT 0 UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) t2, (SELECT @row:=$1-1) r WHERE @row < $2)',
        '/\bRANDOM\(\)/i' => 'RAND()',
        '/\bCLOCK_TIMESTAMP\(\)/i' => 'NOW()',
        '/\bSTATEMENT_TIMESTAMP\(\)/i' => 'NOW()',
        '/\bTRANSACTION_TIMESTAMP\(\)/i' => 'NOW()',
        '/\bTIMEOFDAY\(\)/i' => 'NOW()',
        '/\bCURRENT_TIMESTAMP\(\)/i' => 'CURRENT_TIMESTAMP',
        '/\bNOW\(\)/i' => 'CURRENT_TIMESTAMP',
        '/\bLENGTH\(/i' => 'CHAR_LENGTH(',
        '/\bPOSITION\(/i' => 'LOCATE(',
        '/\bOCTET_LENGTH\(/i' => 'LENGTH(',
        '/\bBIT_LENGTH\(/i' => '(LENGTH($1) * 8)',
        '/([^|])\|\|([^|])/i' => '$1, $2', 
        '/\bSUBSTRING\s*\(\s*([^,]+)\s+FROM\s+(\d+)(?:\s+FOR\s+(\d+))?\s*\)/i' => 'SUBSTRING($1, $2, $3)',
        '/\bSUBSTRING\(/i' => 'SUBSTRING(',
        '/\bLPAD\(/i' => 'LPAD(',
        '/\bRPAD\(/i' => 'RPAD(',
        '/\bLTRIM\(/i' => 'LTRIM(',
        '/\bRTRIM\(/i' => 'RTRIM(',
        '/\bUPPER\(/i' => 'UPPER(',
        '/\bLOWER\(/i' => 'LOWER(',
        '/\bISIMILAR\s+TO\b/i' => 'REGEXP',
        '/\b~\b/i' => 'REGEXP',
        '/\b~\*\b/i' => 'REGEXP',
        '/\b!~\b/i' => 'NOT REGEXP',
        '/\b!~\*\b/i' => 'NOT REGEXP',
        '/\bILIKE\b/i' => 'LIKE',
        '/\bNOT\s+ILIKE\b/i' => 'NOT LIKE',
        '/CREATE\s+UNIQUE\s+INDEX\s+CONCURRENTLY\s+(\w+)/i' => 'CREATE UNIQUE INDEX $1',
        '/CREATE\s+INDEX\s+CONCURRENTLY\s+(\w+)/i' => 'CREATE INDEX $1',
        '/\bUSING\s+BTREE\b/i' => 'USING BTREE',
        '/\bUSING\s+HASH\b/i' => 'USING HASH',
        '/\bUSING\s+GIN\b/i' => '',
        '/\bUSING\s+GIST\b/i' => '',
        '/\bUSING\s+SPGIST\b/i' => '',
        '/\bUSING\s+BRIN\b/i' => '',
        
        '/\bCHECK\s*\([^)]+\)/i' => '',
        '/\bEXCLUDE\s+[^;]+/i' => '',
        '/\bDEFERRABLE\b/i' => '',
        '/\bINITIALLY\s+DEFERRED\b/i' => '',
        '/\bINITIALLY\s+IMMEDIATE\b/i' => '',
        '/\bON\s+CONFLICT\s+[^;]+/i' => '',
        '/\bRETURNING\s+[^;]+/i' => '',
        '/\bWITH\s+\([^)]*\)/i' => '',
        '/\bINHERITS\s*\([^)]+\)/i' => '',
        '/\bTABLESPACE\s+\w+/i' => '',
        '/\bROW_NUMBER\(\)\s+OVER\s*\([^)]*\)/i' => 'ROW_NUMBER() OVER ()',
        '/\bRANK\(\)\s+OVER\s*\([^)]*\)/i' => 'RANK() OVER ()',
        '/\bDENSE_RANK\(\)\s+OVER\s*\([^)]*\)/i' => 'DENSE_RANK() OVER ()',
    ];

    private const SQLITE_PATTERNS = [
        '/\b(\w+)\s+INTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\s+NOT\s+NULL\b/i' => '$1 INT AUTO_INCREMENT PRIMARY KEY NOT NULL',
        '/\b(\w+)\s+INTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i' => '$1 INT AUTO_INCREMENT PRIMARY KEY',
        '/\b(\w+)\s+INTEGER\s+AUTOINCREMENT\b/i' => '$1 INT AUTO_INCREMENT',
        '/\bINTEGER\b/i' => 'INT',
        '/\bREAL\b/i' => 'DOUBLE',
        '/\bNUMERIC\b/i' => 'DECIMAL',
        '/\bTEXT\b/i' => 'TEXT',
        '/\bBLOB\b/i' => 'LONGBLOB',
        '/\bDATE\s*\(\s*[\'"]now[\'"]\s*\)/i' => 'CURDATE()',
        '/\bTIME\s*\(\s*[\'"]now[\'"]\s*\)/i' => 'CURTIME()',
        '/\bDATETIME\s*\(\s*[\'"]now[\'"]\s*\)/i' => 'NOW()',
        '/\bDATE\s*\(\s*([^,)]+)\s*,\s*[\'"][+-]\d+\s+days?[\'"]\s*\)/i' => 'DATE_ADD($1, INTERVAL $2 DAY)',
        '/\bDATE\s*\(\s*([^,)]+)\s*,\s*[\'"][+-]\d+\s+months?[\'"]\s*\)/i' => 'DATE_ADD($1, INTERVAL $2 MONTH)',
        '/\bDATE\s*\(\s*([^,)]+)\s*,\s*[\'"][+-]\d+\s+years?[\'"]\s*\)/i' => 'DATE_ADD($1, INTERVAL $2 YEAR)',
        '/\bJULIANDAY\s*\(\s*([^)]+)\s*\)/i' => 'TO_DAYS($1)',
        '/\bSTRFTIME\s*\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*([^)]+)\s*\)/i' => 'DATE_FORMAT($2, \'$1\')',
        '/\bSUBSTR\s*\(\s*([^,]+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i' => 'SUBSTRING($1, $2, $3)',
        '/\bSUBSTR\s*\(\s*([^,]+)\s*,\s*(\d+)\s*\)/i' => 'SUBSTRING($1, $2)',
        '/\bLENGTH\s*\(\s*([^)]+)\s*\)/i' => 'CHAR_LENGTH($1)',
        '/\bTRIM\s*\(\s*([^)]+)\s*\)/i' => 'TRIM($1)',
        '/\bLTRIM\s*\(\s*([^)]+)\s*\)/i' => 'LTRIM($1)',
        '/\bRTRIM\s*\(\s*([^)]+)\s*\)/i' => 'RTRIM($1)',
        '/\bUPPER\s*\(\s*([^)]+)\s*\)/i' => 'UPPER($1)',
        '/\bLOWER\s*\(\s*([^)]+)\s*\)/i' => 'LOWER($1)',
        '/\bREPLACE\s*\(\s*([^,]+)\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'REPLACE($1, $2, $3)',
        '/\bINSTR\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'LOCATE($2, $1)',
        '/\bGROUP_CONCAT\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'GROUP_CONCAT($1 SEPARATOR $2)',
        '/\bTOTAL\s*\(\s*([^)]+)\s*\)/i' => 'COALESCE(SUM($1), 0)',
        '/\bABS\s*\(\s*([^)]+)\s*\)/i' => 'ABS($1)',
        '/\bROUND\s*\(\s*([^,]+)\s*,\s*(\d+)\s*\)/i' => 'ROUND($1, $2)',
        '/\bROUND\s*\(\s*([^)]+)\s*\)/i' => 'ROUND($1)',
        '/\bRANDOM\s*\(\s*\)/i' => 'RAND()',
        '/\bWITHOUT\s+ROWID\b/i' => '',
        '/\bSTRICT\b/i' => '',
        '/\bAS\s+ROWID\b/i' => '',
        '/PRAGMA\s+table_info\s*\(\s*([^)]+)\s*\);?/i' => 'DESCRIBE $1;',
        '/PRAGMA\s+foreign_keys\s*=\s*ON;?/i' => 'SET FOREIGN_KEY_CHECKS = 1;',
        '/PRAGMA\s+foreign_keys\s*=\s*OFF;?/i' => 'SET FOREIGN_KEY_CHECKS = 0;',
        '/PRAGMA\s+synchronous\s*=\s*[^;]*;?/i' => '-- PRAGMA synchronous removed',
        '/PRAGMA\s+journal_mode\s*=\s*[^;]*;?/i' => '-- PRAGMA journal_mode removed',
        '/PRAGMA\s+cache_size\s*=\s*[^;]*;?/i' => '-- PRAGMA cache_size removed',
        '/PRAGMA\s+temp_store\s*=\s*[^;]*;?/i' => '-- PRAGMA temp_store removed',
        '/PRAGMA\s+[^;]*;?/i' => '-- PRAGMA statement removed',
        '/\bDEFAULT\s+\(\s*([^)]+)\s*\)/i' => 'DEFAULT $1',
        '/\bCONSTRAINT\s+(\w+)\s+CHECK\s*\([^)]+\)/i' => '-- CHECK constraint $1 removed',
        '/CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS\s+(\w+)/i' => 'CREATE INDEX $1',
        '/CREATE\s+UNIQUE\s+INDEX\s+IF\s+NOT\s+EXISTS\s+(\w+)/i' => 'CREATE UNIQUE INDEX $1',
        '/\bHEX\s*\(\s*([^)]+)\s*\)/i' => 'HEX($1)',
        '/\bUNHEX\s*\(\s*([^)]+)\s*\)/i' => 'UNHEX($1)',
        '/\bZEROBLOB\s*\(\s*(\d+)\s*\)/i' => 'REPEAT(CHAR(0), $1)',
        '/\bLAG\s*\(\s*([^,]+)\s*,\s*(\d+)\s*,\s*([^)]+)\s*\)/i' => 'LAG($1, $2, $3)',
        '/\bLEAD\s*\(\s*([^,]+)\s*,\s*(\d+)\s*,\s*([^)]+)\s*\)/i' => 'LEAD($1, $2, $3)',
        '/\bFIRST_VALUE\s*\(\s*([^)]+)\s*\)/i' => 'FIRST_VALUE($1)',
        '/\bLAST_VALUE\s*\(\s*([^)]+)\s*\)/i' => 'LAST_VALUE($1)',
    ];

    private const SQLSERVER_PATTERNS = [
        '/\b(\w+)\s+INT\s+IDENTITY\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)\s+PRIMARY\s+KEY\b/i' => '$1 INT AUTO_INCREMENT PRIMARY KEY',
        '/\b(\w+)\s+BIGINT\s+IDENTITY\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)\s+PRIMARY\s+KEY\b/i' => '$1 BIGINT AUTO_INCREMENT PRIMARY KEY',
        '/\b(\w+)\s+SMALLINT\s+IDENTITY\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)\s+PRIMARY\s+KEY\b/i' => '$1 SMALLINT AUTO_INCREMENT PRIMARY KEY',
        '/\bNVARCHAR\s*\(\s*MAX\s*\)/i' => 'LONGTEXT',
        '/\bVARCHAR\s*\(\s*MAX\s*\)/i' => 'LONGTEXT',
        '/\bNVARCHAR\s*\(\s*(\d+)\s*\)/i' => 'VARCHAR($1)',
        '/\bNTEXT\b/i' => 'LONGTEXT',
        '/\bTEXT\b(?!\s*\()/i' => 'LONGTEXT',
        '/\bUNIQUEIDENTIFIER\b/i' => 'CHAR(36)',
        '/\bIMAGE\b/i' => 'LONGBLOB',
        '/\bVARBINARY\s*\(\s*MAX\s*\)/i' => 'LONGBLOB',
        '/\bVARBINARY\s*\(\s*(\d+)\s*\)/i' => 'VARBINARY($1)',
        '/\bBINARY\s*\(\s*(\d+)\s*\)/i' => 'BINARY($1)',
        '/\bMONEY\b/i' => 'DECIMAL(19,4)',
        '/\bSMALLMONEY\b/i' => 'DECIMAL(10,4)',
        '/\bDATETIME2\s*\(\s*(\d+)\s*\)/i' => 'DATETIME($1)',
        '/\bDATETIME2\b/i' => 'DATETIME',
        '/\bSMALLDATETIME\b/i' => 'DATETIME',
        '/\bTIME\s*\(\s*(\d+)\s*\)/i' => 'TIME($1)',
        '/\bDATE\b/i' => 'DATE',
        '/\bBIT\b/i' => 'TINYINT(1)',
        '/\bTINYINT\b/i' => 'TINYINT',
        '/\bSMALLINT\b/i' => 'SMALLINT',
        '/\bBIGINT\b/i' => 'BIGINT',
        '/\bFLOAT\s*\(\s*(\d+)\s*\)/i' => 'FLOAT($1)',
        '/\bREAL\b/i' => 'FLOAT',
        '/\bDECIMAL\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/i' => 'DECIMAL($1,$2)',
        '/\bNUMERIC\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/i' => 'DECIMAL($1,$2)',
        '/\bIDENTITY\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/i' => 'AUTO_INCREMENT',
        '/\bIDENTITY\b/i' => 'AUTO_INCREMENT',
        '/\bGETDATE\s*\(\s*\)/i' => 'NOW()',
        '/\bSYSDATE\s*\(\s*\)/i' => 'NOW()',
        '/\bGETUTCDATE\s*\(\s*\)/i' => 'UTC_TIMESTAMP()',
        '/\bCURRENT_TIMESTAMP\b/i' => 'CURRENT_TIMESTAMP',
        '/\bNEWID\s*\(\s*\)/i' => 'UUID()',
        '/\bNEWSEQUENTIALID\s*\(\s*\)/i' => 'UUID()',
        '/\bLEN\s*\(\s*([^)]+)\s*\)/i' => 'CHAR_LENGTH($1)',
        '/\bDATALENGTH\s*\(\s*([^)]+)\s*\)/i' => 'LENGTH($1)',
        '/\bISNULL\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'IFNULL($1, $2)',
        '/\bNULLIF\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'NULLIF($1, $2)',
        '/\bCOALESCE\s*\(/i' => 'COALESCE(',
        '/\bCHARINDEX\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'LOCATE($1, $2)',
        '/\bPATINDEX\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'LOCATE($1, $2)',
        '/\bSTUFF\s*\(\s*([^,]+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([^)]+)\s*\)/i' => 'INSERT($1, $2, $3, $4)',
        '/\bREPLICATE\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'REPEAT($1, $2)',
        '/\bREVERSE\s*\(\s*([^)]+)\s*\)/i' => 'REVERSE($1)',
        '/\bLEFT\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'LEFT($1, $2)',
        '/\bRIGHT\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'RIGHT($1, $2)',
        '/\bSUBSTRING\s*\(\s*([^,]+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i' => 'SUBSTRING($1, $2, $3)',
        '/\bLTRIM\s*\(\s*([^)]+)\s*\)/i' => 'LTRIM($1)',
        '/\bRTRIM\s*\(\s*([^)]+)\s*\)/i' => 'RTRIM($1)',
        '/\bUPPER\s*\(\s*([^)]+)\s*\)/i' => 'UPPER($1)',
        '/\bLOWER\s*\(\s*([^)]+)\s*\)/i' => 'LOWER($1)',
        '/\bABS\s*\(\s*([^)]+)\s*\)/i' => 'ABS($1)',
        '/\bCEILING\s*\(\s*([^)]+)\s*\)/i' => 'CEIL($1)',
        '/\bFLOOR\s*\(\s*([^)]+)\s*\)/i' => 'FLOOR($1)',
        '/\bROUND\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'ROUND($1, $2)',
        '/\bPOWER\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'POW($1, $2)',
        '/\bSQRT\s*\(\s*([^)]+)\s*\)/i' => 'SQRT($1)',
        '/\bRAND\s*\(\s*([^)]*)\s*\)/i' => 'RAND($1)',
        
        '/\bDATEADD\s*\(\s*YEAR\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'DATE_ADD($2, INTERVAL $1 YEAR)',
        '/\bDATEADD\s*\(\s*MONTH\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'DATE_ADD($2, INTERVAL $1 MONTH)',
        '/\bDATEADD\s*\(\s*DAY\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'DATE_ADD($2, INTERVAL $1 DAY)',
        '/\bDATEADD\s*\(\s*HOUR\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'DATE_ADD($2, INTERVAL $1 HOUR)',
        '/\bDATEADD\s*\(\s*MINUTE\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'DATE_ADD($2, INTERVAL $1 MINUTE)',
        '/\bDATEADD\s*\(\s*SECOND\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'DATE_ADD($2, INTERVAL $1 SECOND)',
        '/\bDATEDIFF\s*\(\s*DAY\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'DATEDIFF($2, $1)',
        '/\bDATEDIFF\s*\(\s*MONTH\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'PERIOD_DIFF(DATE_FORMAT($2, "%Y%m"), DATE_FORMAT($1, "%Y%m"))',
        '/\bDATEDIFF\s*\(\s*YEAR\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => '(YEAR($2) - YEAR($1))',
        '/\bDATEPART\s*\(\s*YEAR\s*,\s*([^)]+)\s*\)/i' => 'YEAR($1)',
        '/\bDATEPART\s*\(\s*MONTH\s*,\s*([^)]+)\s*\)/i' => 'MONTH($1)',
        '/\bDATEPART\s*\(\s*DAY\s*,\s*([^)]+)\s*\)/i' => 'DAY($1)',
        '/\bDATEPART\s*\(\s*HOUR\s*,\s*([^)]+)\s*\)/i' => 'HOUR($1)',
        '/\bDATEPART\s*\(\s*MINUTE\s*,\s*([^)]+)\s*\)/i' => 'MINUTE($1)',
        '/\bDATEPART\s*\(\s*SECOND\s*,\s*([^)]+)\s*\)/i' => 'SECOND($1)',
        
        '/\bIIF\s*\(\s*([^,]+)\s*,\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'IF($1, $2, $3)',
        '/\bCHOOSE\s*\(\s*([^,]+)\s*,\s*([^)]+)\s*\)/i' => 'ELT($1, $2)',
        
        '/\[([^\]]+)\]/g' => '`$1`',
        '/\bGO\s*$/m' => ';',
        '/\bGO\s+/i' => '; -- GO replaced with semicolon',
        
        '/BEGIN\s+TRANSACTION\b/i' => 'START TRANSACTION',
        '/BEGIN\s+TRAN\b/i' => 'START TRANSACTION',
        '/COMMIT\s+TRANSACTION\b/i' => 'COMMIT',
        '/COMMIT\s+TRAN\b/i' => 'COMMIT',
        '/ROLLBACK\s+TRANSACTION\b/i' => 'ROLLBACK',
        '/ROLLBACK\s+TRAN\b/i' => 'ROLLBACK',
        
        '/SET\s+ANSI_NULLS\s+(ON|OFF)\s*;?/i' => '-- ANSI_NULLS setting removed',
        '/SET\s+QUOTED_IDENTIFIER\s+(ON|OFF)\s*;?/i' => '-- QUOTED_IDENTIFIER setting removed',
        '/SET\s+ANSI_PADDING\s+(ON|OFF)\s*;?/i' => '-- ANSI_PADDING setting removed',
        '/SET\s+ANSI_WARNINGS\s+(ON|OFF)\s*;?/i' => '-- ANSI_WARNINGS setting removed',
        '/SET\s+ARITHABORT\s+(ON|OFF)\s*;?/i' => '-- ARITHABORT setting removed',
        '/SET\s+CONCAT_NULL_YIELDS_NULL\s+(ON|OFF)\s*;?/i' => '-- CONCAT_NULL_YIELDS_NULL setting removed',
        '/SET\s+NUMERIC_ROUNDABORT\s+(ON|OFF)\s*;?/i' => '-- NUMERIC_ROUNDABORT setting removed',
        '/SET\s+XACT_ABORT\s+(ON|OFF)\s*;?/i' => '-- XACT_ABORT setting removed',
        
        '/WITH\s*\(\s*NOLOCK\s*\)/i' => '',
        '/WITH\s*\(\s*READUNCOMMITTED\s*\)/i' => '',
        '/WITH\s*\(\s*[^)]*LOCK[^)]*\s*\)/i' => '',
        '/OPTION\s*\([^)]*\)/i' => '',
        
        '/\bROW_NUMBER\s*\(\s*\)\s+OVER\s*\([^)]*\)/i' => 'ROW_NUMBER() OVER ()',
        '/\bRANK\s*\(\s*\)\s+OVER\s*\([^)]*\)/i' => 'RANK() OVER ()',
        '/\bDENSE_RANK\s*\(\s*\)\s+OVER\s*\([^)]*\)/i' => 'DENSE_RANK() OVER ()',
        '/\bNTILE\s*\(\s*([^)]+)\s*\)\s+OVER\s*\([^)]*\)/i' => 'NTILE($1) OVER ()',
    ];

    private const ORACLE_PATTERNS = [
        '/\bVARCHAR2\(/i' => 'VARCHAR(',
        '/\bNVARCHAR2\(/i' => 'VARCHAR(',
        '/\bNUMBER\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/i' => 'DECIMAL($1,$2)',
        '/\bNUMBER\s*\(\s*(\d+)\s*\)/i' => 'DECIMAL($1,0)',
        '/\bNUMBER\b/i' => 'DECIMAL(65,30)',
        '/\bCLOB\b/i' => 'LONGTEXT',
        '/\bBLOB\b/i' => 'LONGBLOB',
        '/\bRAW\(/i' => 'VARBINARY(',
        '/\bLONG\s+RAW\b/i' => 'LONGBLOB',
        '/\bDATE\b/i' => 'DATETIME',
        '/\bTIMESTAMP\(\d+\)/i' => 'TIMESTAMP',
        
        '/\bSYSDATE\b/i' => 'NOW()',
        '/\bCURRENT_TIMESTAMP\b/i' => 'CURRENT_TIMESTAMP',
        '/\bSEQ_(\w+)\.NEXTVAL/i' => '0 -- AUTO_INCREMENT will handle this',
        '/\bNVL\(/i' => 'IFNULL(',
        '/\bNVL2\(/i' => 'IF(',
        '/\bDECODE\(/i' => 'CASE WHEN',
        '/\bSUBSTR\(/i' => 'SUBSTRING(',
        '/\bINSTR\(/i' => 'LOCATE(',
        '/\bLENGTH\(/i' => 'CHAR_LENGTH(',
        '/\bTRUNC\(/i' => 'TRUNCATE(',
        
        '/FROM\s+DUAL\b/i' => '',
        '/\bDUAL\b/i' => '',
        
        '/CREATE\s+SEQUENCE\s+(\w+)/i' => '-- CONVERTED SEQUENCE: $1 (handled by AUTO_INCREMENT)',
        
        '/\|\|/i' => 'CONCAT',
        '/\bq\'([^\']*)\'/i' => "'$1'",
    ];

    public function makeCompatible(string $sql): string
    {
        try {
            $this->detectedDatabaseType = $this->detectDatabaseType($sql);
            $sql = $this->fixSeverelyCorruptedSql($sql);
            $sql = $this->preProcessMalformedSql($sql);
            $sql = $this->applyCrossDatabaseTranspilation($sql);
            $sql = $this->removeDeprecatedVariables($sql);
            $sql = $this->fixSyntaxCompatibility($sql);
            $sql = $this->fixTruncatedStatements($sql);
            $sql = $this->fixMalformedInserts($sql);
            $this->validateSql($sql);
            return $sql;
        } catch (\Exception $e) {
            return $sql;
        }
    }

    private function fixSeverelyCorruptedSql(string $sql): string
    {
        
        $hasCorruption = (
            strpos($sql, 'NOT NULLINSERT INTO') !== false ||
            strpos($sql, 'VALUESINSERT INTO') !== false ||
            preg_match('/\)[^;\s]*INSERT\s+INTO/i', $sql)
        );
        
        if (!$hasCorruption) {
            return $sql;
        }
        
        $sql = preg_replace('/NOT\s+NULL\s*INSERT\s+INTO/i', 'NOT NULL); INSERT INTO', $sql);
        
        $sql = preg_replace('/VALUES\s*INSERT\s+INTO/i', 'VALUES; INSERT INTO', $sql);
        
        $sql = preg_replace('/([a-zA-Z0-9_`\)])INSERT\s+INTO/i', '$1; INSERT INTO', $sql);
        
        $sql = preg_replace('/([a-zA-Z0-9_`\)])CREATE\s+TABLE/i', '$1; CREATE TABLE', $sql);
        
        $sql = preg_replace('/INSERT\s+INTO\s+(`?\w+`?)\s+\([^)]+\)\s+VALUES\s*INSERT\s+INTO\s+\1/i', 'INSERT INTO $1', $sql);
        
        return $sql;
    }

    private function preProcessMalformedSql(string $sql): string
    {
        $lines = explode("\n", $sql);
        $processedLines = [];
        $skipCount = 0;
        $inInsertStatement = false;
        $lastValidInsert = null;
        
        foreach ($lines as $i => $line) {
            $originalLine = $line;
            $trimmed = trim($line);
            
            if (empty($trimmed)) {
                $processedLines[] = $originalLine;
                continue;
            }
            
            if (preg_match('/^INSERT\s+INTO\s+`?([^`\s]+)`?/i', $trimmed, $matches)) {
                $inInsertStatement = true;
                $lastValidInsert = $matches[1];
                $processedLines[] = $line;
                continue;
            }
            
            if (preg_match('/^(UPDATE|DELETE|CREATE|DROP|ALTER|SELECT|SET|USE|SHOW|COMMIT|START|BEGIN)/i', $trimmed)) {
                $inInsertStatement = false;
                $processedLines[] = $line;
                continue;
            }
            
            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                $processedLines[] = $line;
                continue;
            }
            
            $isMalformed = false;
            $reason = '';
            
            if (preg_match('/^,/', $trimmed)) {
                $isMalformed = true;
                $reason = 'COMMA_START';
            }
            
            elseif (preg_match('/^\),?/', $trimmed)) {
                $isMalformed = true;
                $reason = 'PAREN_CLOSE';
            }
            
            elseif (preg_match('/^[\d\.\-\s]+,/', $trimmed)) {
                $isMalformed = true;
                $reason = 'NUMERIC_FRAGMENT';
            }
            
            elseif (preg_match('/^[\'"][^\'";]*$/', $trimmed) && !preg_match('/VALUES\s*\(/', $trimmed)) {
                $isMalformed = true;
                $reason = 'UNTERMINATED_STRING';
            }
            
            elseif (preg_match('/^[\'"]?\{[^}]*$|^[^{]*\}[\'"]?$/', $trimmed) && !preg_match('/^(INSERT|UPDATE|DELETE|CREATE)/i', $trimmed)) {
                $isMalformed = true;
                $reason = 'PARTIAL_JSON';
            }
            
            elseif (preg_match('/^[\'"][^\'";]*[\'"].*,\s*$/', $trimmed) && !preg_match('/VALUES|INSERT/i', $trimmed)) {
                $isMalformed = true;
                $reason = 'ORPHANED_VALUE';
            }
            
            elseif (preg_match('/^\([^)]*,\s*$/', $trimmed) || preg_match('/^[^(]*\),?\s*$/', $trimmed)) {
                $isMalformed = true;
                $reason = 'INCOMPLETE_TUPLE';
            }
            
            elseif ($this->hasUnbalancedQuotes($trimmed)) {
                $isMalformed = true;
                $reason = 'UNBALANCED_QUOTES';
            }
            
            elseif (preg_match('/^[\.\;\:\!\@\#\$\%\^\&\*]/', $trimmed)) {
                $isMalformed = true;
                $reason = 'INVALID_START_CHAR';
            }
            
            elseif ($inInsertStatement && !preg_match('/^(INSERT|VALUES|\(|\-\-)/i', $trimmed) && 
                    preg_match('/^[^A-Z][^=]*[,\)]/', $trimmed)) {
                $isMalformed = true;
                $reason = 'INSERT_CONTINUATION';
            }
            
            if ($isMalformed) {
                $skipCount++;
                $preview = substr($trimmed, 0, 50);
                $processedLines[] = "-- SKIP[{$reason}#{$skipCount}]: {$preview}...";
                continue;
            }
            
            $processedLines[] = $line;
        }
        
        if ($skipCount > 0) {
            array_unshift($processedLines, "-- PREPROCESSOR: Filtered {$skipCount} malformed lines using aggressive pattern matching");
        }
        
        return implode("\n", $processedLines);
    }
    
    private function hasUnbalancedQuotes(string $line): bool
    {
        $singleQuotes = substr_count($line, "'") - substr_count($line, "\\'");
        
        $doubleQuotes = substr_count($line, '"') - substr_count($line, '\\"');
        
        return ($singleQuotes % 2 !== 0) || ($doubleQuotes % 2 !== 0);
    }

    private function removeDeprecatedVariables(string $sql): string
    {
        $lines = explode("\n", $sql);
        $cleanLines = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line) || str_starts_with($line, '--') || str_starts_with($line, '#')) {
                $cleanLines[] = $line;
                continue;
            }
            if (preg_match('/^SET\s+@@?([^=\s]+)\s*=/i', $line, $matches)) {
                $variable = strtolower($matches[1]);
                
                if (array_key_exists($variable, self::DEPRECATED_VARIABLES)) {
                    if (self::DEPRECATED_VARIABLES[$variable] === null) {
                        $cleanLines[] = "-- DEPRECATED VARIABLE REMOVED: {$line}";
                        continue;
                    } else {
                        $replacement = self::DEPRECATED_VARIABLES[$variable];
                        $line = preg_replace('/@@?' . preg_quote($variable, '/') . '/i', $replacement, $line);
                    }
                }
            }
            
            $cleanLines[] = $line;
        }
        
        return implode("\n", $cleanLines);
    }

    private function fixSyntaxCompatibility(string $sql): string
    {
        foreach (self::SYNTAX_PATTERNS as $pattern => $replacement) {
            $newSql = preg_replace($pattern, $replacement, $sql);
            if ($newSql !== $sql) {
                $sql = $newSql;
            }
        }
        
        return $sql;
    }

    private function fixTruncatedStatements(string $sql): string
    {
        $lines = explode("\n", $sql);
        $fixedLines = [];
        $lastInsertTable = null;
        $lastInsertColumns = null;
        $currentInsertStatement = '';
        
        foreach ($lines as $i => $line) {
            $line = trim($line);
            
            if (empty($line)) {
                $fixedLines[] = $line;
                continue;
            }
            
            if (preg_match('/^INSERT\s+INTO\s+`?([^`\s]+)`?\s*(\([^)]+\))?\s+VALUES/i', $line, $matches)) {
                $lastInsertTable = $matches[1];
                $lastInsertColumns = $matches[2] ?? '';
                $fixedLines[] = $line;
                continue;
            }
            
            if (str_starts_with($line, ',') && $lastInsertTable) {
                $values = ltrim($line, ',');
                $values = trim($values);
                
                if (!empty($values)) {
                    if (!str_starts_with($values, '(')) {
                        $values = '(' . $values;
                    }
                    
                    if (!str_ends_with($values, ')') && !str_ends_with($values, '),') && !str_ends_with($values, ');')) {
                        $parenCount = substr_count($values, '(') - substr_count($values, ')');
                        if ($parenCount > 0) {
                            $values .= str_repeat(')', $parenCount);
                        }
                    }
                    
                    $newStatement = "INSERT INTO `{$lastInsertTable}` {$lastInsertColumns} VALUES {$values};";
                    $fixedLines[] = $newStatement;
                    continue;
                }
            }
            
            if (preg_match('/^\),?\s*\(/', $line) && $lastInsertTable) {
                $values = $line;
                if (str_starts_with($values, '),')) {
                    $values = substr($values, 2);
                } elseif (str_starts_with($values, ')')) {
                    $values = substr($values, 1);
                }
                $values = trim($values);
                
                if (!empty($values) && str_starts_with($values, '(')) {
                    $newStatement = "INSERT INTO `{$lastInsertTable}` {$lastInsertColumns} VALUES {$values};";
                    $fixedLines[] = $newStatement;
                    continue;
                }
            }
            
            if ($lastInsertTable && preg_match('/^\(.*\)(?:,\s*\(.*\))*[,;]?$/', $line)) {
                $values = $line;
                $values = rtrim($values, ',;') . ';';
                
                $newStatement = "INSERT INTO `{$lastInsertTable}` {$lastInsertColumns} VALUES {$values}";
                $fixedLines[] = $newStatement;
                continue;
            }
            
            if ($lastInsertTable && preg_match('/^[^(]*[\'"][^\'";]*$/', $line)) {
                $fixedLines[] = "-- SKIPPED INCOMPLETE DATA: " . substr($line, 0, 100) . "...";
                continue;
            }
            
            if (!preg_match('/^[,\s\(\)]/', $line) && !empty($line)) {
                $lastInsertTable = null;
                $lastInsertColumns = null;
            }
            
            $fixedLines[] = $line;
        }
        
        return implode("\n", $fixedLines);
    }

    private function fixMalformedInserts(string $sql): string
    {
        $lines = explode("\n", $sql);
        $fixedLines = [];
        $skipCount = 0;
        $totalLines = count($lines);
        
        foreach ($lines as $lineIndex => $line) {
            $originalLine = $line;
            $trimmed = trim($line);
            
            if (empty($trimmed) || str_starts_with($trimmed, '--')) {
                $fixedLines[] = $originalLine;
                continue;
            }
            
            if (preg_match('/^(INSERT\s+INTO|UPDATE|DELETE|CREATE|DROP|ALTER|SELECT|SET|USE|SHOW)/i', $trimmed)) {
                $fixedLines[] = $line;
                continue;
            }
            
            $shouldSkip = false;
            $skipReason = '';
            
            if (preg_match('/^[,\)\.\;\:\!\@\#\$\%\^\&\*\+\=\[\]\{\}\|\\\]/', $trimmed)) {
                $shouldSkip = true;
                $skipReason = 'PUNCT_START';
            }
            
            elseif (preg_match('/^[\d\.\-\s]+[,\)]/', $trimmed)) {
                $shouldSkip = true;
                $skipReason = 'DATA_FRAGMENT';
            }
            
            elseif ($this->hasUnbalancedParentheses($trimmed)) {
                $shouldSkip = true;
                $skipReason = 'UNBAL_PAREN';
            }
            
            elseif (preg_match('/^\([^)]*$|^[^(]*\)$/', $trimmed) && !preg_match('/^(INSERT|VALUES)/i', $trimmed)) {
                $shouldSkip = true;
                $skipReason = 'INCOMPLETE_VALUES';
            }
            
            elseif (preg_match('/^[\'"][^\'";]*[\'"]?\s*,?\s*$/', $trimmed) && !preg_match('/^(INSERT|UPDATE|DELETE)/i', $trimmed)) {
                $shouldSkip = true;
                $skipReason = 'ORPHAN_STRING';
            }
            
            elseif (preg_match('/^[\d\s\.\,\-\(\)\'\"]+$/', $trimmed) && !preg_match('/^(INSERT|VALUES|\-\-)/i', $trimmed)) {
                $shouldSkip = true;
                $skipReason = 'CHAR_SOUP';
            }
            
            elseif (preg_match('/[\{\}]/', $trimmed) && !preg_match('/^(INSERT|UPDATE|VALUES)/i', $trimmed) && $this->looksLikeBrokenJson($trimmed)) {
                $shouldSkip = true;
                $skipReason = 'BROKEN_JSON';
            }
            
            elseif (strlen($trimmed) < 10 && preg_match('/[,\(\)\'\"]/', $trimmed) && !preg_match('/^(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|SELECT|SET|USE|SHOW)/i', $trimmed)) {
                $shouldSkip = true;
                $skipReason = 'TOO_SHORT';
            }
            
            if ($shouldSkip) {
                $skipCount++;
                $preview = substr($trimmed, 0, 60);
                $fixedLines[] = "-- FINAL_SKIP[{$skipReason}#{$skipCount}]: {$preview}...";
                continue;
            }
            
            $fixedLines[] = $line;
        }
        
        $successRate = (($totalLines - $skipCount) / $totalLines) * 100;
        
        if ($skipCount > 0) {
            array_unshift($fixedLines, "-- FINAL CLEANUP: Skipped {$skipCount}/{$totalLines} lines (" . round($successRate, 1) . "% preserved)");
        }
        
        return implode("\n", $fixedLines);
    }
    
    private function hasUnbalancedParentheses(string $line): bool
    {
        $openCount = substr_count($line, '(');
        $closeCount = substr_count($line, ')');
        return $openCount !== $closeCount;
    }
    
    private function looksLikeBrokenJson(string $line): bool
    {
        return preg_match('/\{[^}]*$|^[^{]*\}|\\\\\"[^"]*$/', $line);
    }

    private function validateSql(string $sql): void
    {
        $problematicPatterns = [
            '/,\s*,/' => 'Double comma detected',
            '/VALUES\s*,/' => 'VALUES followed by comma',
            '/INSERT\s+INTO\s+,/' => 'INSERT INTO followed by comma',
        ];
        
        foreach ($problematicPatterns as $pattern => $description) {
            if (preg_match($pattern, $sql)) {
            }
        }
    }

    public function hasUnsupportedFeatures(string $sql): array
    {
        $unsupported = [];
        
        $complexPatterns = [
            '/SPATIAL\s+INDEX/i' => 'Spatial indexes may not be fully supported',
            '/FULLTEXT\s+INDEX.*WITH\s+PARSER/i' => 'Custom fulltext parsers not supported',
            '/PARTITION\s+BY/i' => 'Table partitioning may need manual review',
            '/ENGINE=FEDERATED/i' => 'FEDERATED engine not commonly supported',
            '/ENGINE=ARCHIVE/i' => 'ARCHIVE engine may not be available',
        ];
        
        foreach ($complexPatterns as $pattern => $message) {
            if (preg_match($pattern, $sql)) {
                $unsupported[] = $message;
            }
        }
        
        return $unsupported;
    }

    public function getCompatibilityReport(string $sql): array
    {
        $report = [
            'compatible' => true,
            'warnings' => [],
            'errors' => [],
            'unsupported_features' => [],
            'applied_fixes' => []
        ];
        
        foreach (self::DEPRECATED_VARIABLES as $var => $replacement) {
            if (preg_match('/SET\s+@@?' . preg_quote($var, '/') . '/i', $sql)) {
                if ($replacement === null) {
                    $report['applied_fixes'][] = "Removed deprecated variable: {$var}";
                } else {
                    $report['applied_fixes'][] = "Replaced deprecated variable: {$var} -> {$replacement}";
                }
            }
        }
        
        foreach (self::SYNTAX_PATTERNS as $pattern => $replacement) {
            if (preg_match($pattern, $sql)) {
                $report['applied_fixes'][] = "Fixed syntax: {$pattern} -> {$replacement}";
            }
        }
        
        $unsupported = $this->hasUnsupportedFeatures($sql);
        if (!empty($unsupported)) {
            $report['unsupported_features'] = $unsupported;
            $report['warnings'] = array_merge($report['warnings'], $unsupported);
        }
        
        if (preg_match('/^,\s*\(/m', $sql)) {
            $report['applied_fixes'][] = "Fixed truncated INSERT statements";
        }
        
        return $report;
    }

    private function detectDatabaseType(string $sql): ?string
    {
        if (preg_match('/\b(SERIAL|BIGSERIAL|BOOLEAN|BYTEA|UUID|TIMESTAMPTZ|NEXTVAL|SUBSTRING|GENERATE_SERIES)\b/i', $sql) ||
            preg_match('/CREATE\s+SEQUENCE/i', $sql) ||
            preg_match('/\|\|/i', $sql)) {
            return 'postgresql';
        }

        if (preg_match('/\b(AUTOINCREMENT|WITHOUT\s+ROWID|STRICT|PRAGMA)\b/i', $sql) ||
            preg_match('/INTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT/i', $sql)) {
            return 'sqlite';
        }

        if (preg_match('/\b(NVARCHAR|NTEXT|UNIQUEIDENTIFIER|IDENTITY|GETDATE|NEWID|CHARINDEX|STUFF)\b/i', $sql) ||
            preg_match('/\[[\w\s]+\]/i', $sql) ||
            preg_match('/\bGO\b/i', $sql) ||
            preg_match('/SET\s+(ANSI_NULLS|QUOTED_IDENTIFIER|ANSI_PADDING)/i', $sql)) {
            return 'sqlserver';
        }
        if (preg_match('/\b(VARCHAR2|NUMBER|CLOB|BLOB|SYSDATE|NEXTVAL|NVL|DECODE|DUAL)\b/i', $sql) ||
            preg_match('/CREATE\s+SEQUENCE/i', $sql) ||
            preg_match('/SEQ_\w+\.NEXTVAL/i', $sql)) {
            return 'oracle';
        }

        if (preg_match('/\b(AUTO_INCREMENT|TINYINT|MEDIUMINT|LONGTEXT|LONGBLOB|ENUM|SET)\b/i', $sql) ||
            preg_match('/ENGINE\s*=/i', $sql) ||
            preg_match('/DEFAULT\s+CHARSET/i', $sql)) {
            return 'mysql';
        }

        return null;
    }

    private function applyCrossDatabaseTranspilation(string $sql): string
    {
        if (!$this->detectedDatabaseType || $this->detectedDatabaseType === 'mysql') {
            return $sql;
        }

        $patterns = match ($this->detectedDatabaseType) {
            'postgresql' => self::POSTGRESQL_PATTERNS,
            'sqlite' => self::SQLITE_PATTERNS,
            'sqlserver' => self::SQLSERVER_PATTERNS,
            'oracle' => self::ORACLE_PATTERNS,
            default => []
        };

        $appliedTranspilations = [];
        
        foreach ($patterns as $pattern => $replacement) {
            $newSql = preg_replace($pattern, $replacement, $sql);
            if ($newSql !== $sql) {
                $appliedTranspilations[] = "Transpiled {$this->detectedDatabaseType} syntax: {$pattern} -> {$replacement}";
                $sql = $newSql;
            }
        }


        return $sql;
    }

    public function getDetectedDatabaseType(): ?string
    {
        return $this->detectedDatabaseType;
    }

    public function wasTranspilationApplied(): bool
    {
        return $this->detectedDatabaseType !== null && $this->detectedDatabaseType !== 'mysql';
    }

    public function getTranspilationFeatures(): array
    {
        if (!$this->detectedDatabaseType || $this->detectedDatabaseType === 'mysql') {
            return [];
        }

        $features = match ($this->detectedDatabaseType) {
            'postgresql' => [
                'Data Types' => ['SERIAL/BIGSERIAL → AUTO_INCREMENT', 'BOOLEAN → TINYINT(1)', 'UUID → CHAR(36)', 'Arrays → JSON'],
                'Functions' => ['RANDOM() → RAND()', 'LENGTH() → CHAR_LENGTH()', 'String concatenation (||) → CONCAT()'],
                'Features' => ['Sequences → AUTO_INCREMENT', 'CONCURRENT indexes → regular indexes']
            ],
            'sqlite' => [
                'Data Types' => ['AUTOINCREMENT → AUTO_INCREMENT', 'REAL → DOUBLE', 'NUMERIC → DECIMAL'],
                'Functions' => ['SUBSTR() → SUBSTRING()', 'LENGTH() → CHAR_LENGTH()'],
                'Features' => ['PRAGMA statements → MySQL equivalents', 'WITHOUT ROWID removed']
            ],
            'sqlserver' => [
                'Data Types' => ['NVARCHAR → VARCHAR', 'IDENTITY → AUTO_INCREMENT', 'BIT → TINYINT(1)', 'MONEY → DECIMAL'],
                'Functions' => ['GETDATE() → NOW()', 'LEN() → LENGTH()', 'ISNULL() → IFNULL()'],
                'Features' => ['Square brackets → backticks', 'GO statements → semicolons']
            ],
            'oracle' => [
                'Data Types' => ['VARCHAR2 → VARCHAR', 'NUMBER → DECIMAL', 'CLOB → LONGTEXT', 'DATE → DATETIME'],
                'Functions' => ['SYSDATE → NOW()', 'NVL() → IFNULL()', 'SUBSTR() → SUBSTRING()'],
                'Features' => ['Sequences → AUTO_INCREMENT', 'DUAL table references removed']
            ],
            default => []
        };

        return $features;
    }
}
