<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Analytics;

use Pterodactyl\Models\Database;
use Pterodactyl\Extensions\SqlDynDatabaseConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PDO;
use Exception;

class IndexAnalyzer
{
    public function __construct(
        protected SqlDynDatabaseConnection $dynamic
    ) {}

    public function analyzeIndexes(Database $database, bool $forceRefresh = false): array
    {
        $cacheKey = "index_analysis_{$database->id}";
        
        if (!$forceRefresh && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $pdo = app('db')->connection('dynamic')->getPdo();
            
            $analysis = [
                'database_name' => $database->database,
                'analysis_timestamp' => now()->toISOString(),
                'tables' => $this->analyzeAllTables($pdo, $database->database),
                'recommendations' => [],
                'performance_impact' => [],
                'summary' => [
                    'total_tables' => 0,
                    'total_indexes' => 0,
                    'unused_indexes' => 0,
                    'missing_indexes' => 0,
                    'redundant_indexes' => 0,
                    'performance_score' => 0
                ]
            ];

            $analysis['recommendations'] = $this->generateRecommendations($analysis['tables']);
            $analysis['performance_impact'] = $this->calculatePerformanceImpact($analysis['tables']);
            $analysis['summary'] = $this->calculateSummary($analysis['tables'], $analysis['recommendations']);

            Cache::put($cacheKey, $analysis, 3600);

            return $analysis;

        } catch (Exception $e) {
            Log::error('Index Analyzer: Failed to analyze database indexes', [
                'database_id' => $database->id,
                'database_name' => $database->database,
                'error' => $e->getMessage()
            ]);

            return [
                'error' => true,
                'message' => 'Failed to analyze database indexes: ' . $e->getMessage(),
                'database_name' => $database->database,
                'analysis_timestamp' => now()->toISOString()
            ];
        }
    }

    protected function analyzeAllTables(PDO $pdo, string $databaseName): array
    {
        $tables = [];
        
        $stmt = $pdo->prepare("
            SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, AUTO_INCREMENT
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([$databaseName]);
        $tableList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tableList as $tableInfo) {
            $tableName = $tableInfo['TABLE_NAME'];
            
            $tables[$tableName] = [
                'name' => $tableName,
                'engine' => $tableInfo['ENGINE'],
                'rows' => (int) ($tableInfo['TABLE_ROWS'] ?? 0),
                'data_size' => (int) ($tableInfo['DATA_LENGTH'] ?? 0),
                'index_size' => (int) ($tableInfo['INDEX_LENGTH'] ?? 0),
                'auto_increment' => $tableInfo['AUTO_INCREMENT'],
                'columns' => $this->getTableColumns($pdo, $databaseName, $tableName),
                'indexes' => $this->getTableIndexes($pdo, $databaseName, $tableName),
                'foreign_keys' => $this->getTableForeignKeys($pdo, $databaseName, $tableName),
                'query_patterns' => $this->analyzeQueryPatterns($pdo, $tableName),
                'index_usage' => $this->getIndexUsageStats($pdo, $databaseName, $tableName),
                'recommendations' => []
            ];
        }

        return $tables;
    }

    protected function getTableColumns(PDO $pdo, string $databaseName, string $tableName): array
    {
        $stmt = $pdo->prepare("
            SELECT 
                COLUMN_NAME,
                DATA_TYPE,
                IS_NULLABLE,
                COLUMN_KEY,
                COLUMN_DEFAULT,
                EXTRA,
                CHARACTER_MAXIMUM_LENGTH,
                NUMERIC_PRECISION,
                NUMERIC_SCALE
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ");
        $stmt->execute([$databaseName, $tableName]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function getTableIndexes(PDO $pdo, string $databaseName, string $tableName): array
    {
        $stmt = $pdo->prepare("
            SELECT 
                INDEX_NAME,
                COLUMN_NAME,
                SEQ_IN_INDEX,
                NON_UNIQUE,
                INDEX_TYPE,
                CARDINALITY,
                SUB_PART,
                NULLABLE
            FROM information_schema.STATISTICS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ");
        $stmt->execute([$databaseName, $tableName]);
        $indexData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexes = [];
        foreach ($indexData as $row) {
            $indexName = $row['INDEX_NAME'];
            if (!isset($indexes[$indexName])) {
                $indexes[$indexName] = [
                    'name' => $indexName,
                    'type' => $row['INDEX_TYPE'],
                    'unique' => $row['NON_UNIQUE'] == 0,
                    'columns' => [],
                    'cardinality' => 0,
                    'size_estimate' => 0
                ];
            }
            
            $indexes[$indexName]['columns'][] = [
                'name' => $row['COLUMN_NAME'],
                'position' => $row['SEQ_IN_INDEX'],
                'sub_part' => $row['SUB_PART'],
                'nullable' => $row['NULLABLE'] === 'YES'
            ];
            
            $indexes[$indexName]['cardinality'] += (int) ($row['CARDINALITY'] ?? 0);
        }

        return array_values($indexes);
    }

    protected function getTableForeignKeys(PDO $pdo, string $databaseName, string $tableName): array
    {
        $stmt = $pdo->prepare("
            SELECT 
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                COALESCE(rc.UPDATE_RULE, 'RESTRICT') as UPDATE_RULE,
                COALESCE(rc.DELETE_RULE, 'RESTRICT') as DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE kcu
            LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS rc 
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME 
                AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
        ");
        $stmt->execute([$databaseName, $tableName]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function analyzeQueryPatterns(PDO $pdo, string $tableName): array
    {
        return [
            'common_where_columns' => [],
            'common_join_columns' => [],
            'common_order_columns' => [],
            'slow_queries' => []
        ];
    }

    protected function getIndexUsageStats(PDO $pdo, string $databaseName, string $tableName): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    INDEX_NAME,
                    COUNT_FETCH,
                    COUNT_INSERT,
                    COUNT_UPDATE,
                    COUNT_DELETE
                FROM performance_schema.table_io_waits_summary_by_index_usage 
                WHERE OBJECT_SCHEMA = ? AND OBJECT_NAME = ?
            ");
            $stmt->execute([$databaseName, $tableName]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    protected function generateRecommendations(array $tables): array
    {
        $recommendations = [];

        foreach ($tables as $table) {
            $tableRecommendations = [];

            if (!$this->hasPrimaryKey($table['indexes'])) {
                $tableRecommendations[] = [
                    'type' => 'missing_primary_key',
                    'priority' => 'high',
                    'title' => 'Missing Primary Key',
                    'description' => "Table '{$table['name']}' doesn't have a primary key. This can severely impact performance.",
                    'recommendation' => 'Add a primary key, preferably an auto-incrementing integer.',
                    'sql_example' => "ALTER TABLE `{$table['name']}` ADD COLUMN `id` INT AUTO_INCREMENT PRIMARY KEY FIRST;",
                    'impact' => 'High performance improvement, better replication'
                ];
            }

            foreach ($table['foreign_keys'] as $fk) {
                if (!$this->hasIndexOnColumn($table['indexes'], $fk['COLUMN_NAME'])) {
                    $tableRecommendations[] = [
                        'type' => 'missing_foreign_key_index',
                        'priority' => 'medium',
                        'title' => 'Missing Foreign Key Index',
                        'description' => "Foreign key column '{$fk['COLUMN_NAME']}' should have an index.",
                        'recommendation' => 'Create an index on the foreign key column.',
                        'sql_example' => "CREATE INDEX `idx_{$table['name']}_{$fk['COLUMN_NAME']}` ON `{$table['name']}` (`{$fk['COLUMN_NAME']}`);",
                        'impact' => 'Improved JOIN performance'
                    ];
                }
            }

            foreach ($table['indexes'] as $index) {
                if ($index['name'] !== 'PRIMARY' && $this->isIndexUnused($index, $table['index_usage'])) {
                    $tableRecommendations[] = [
                        'type' => 'unused_index',
                        'priority' => 'low',
                        'title' => 'Unused Index',
                        'description' => "Index '{$index['name']}' appears to be unused and may be consuming unnecessary space.",
                        'recommendation' => 'Consider dropping this index if it\'s confirmed to be unused.',
                        'sql_example' => "DROP INDEX `{$index['name']}` ON `{$table['name']}`;",
                        'impact' => 'Reduced storage usage, faster INSERT/UPDATE/DELETE'
                    ];
                }
            }

            $redundantIndexes = $this->findRedundantIndexes($table['indexes']);
            foreach ($redundantIndexes as $redundant) {
                $tableRecommendations[] = [
                    'type' => 'redundant_index',
                    'priority' => 'medium',
                    'title' => 'Redundant Index',
                    'description' => "Index '{$redundant['redundant']}' is redundant with '{$redundant['primary']}'.",
                    'recommendation' => 'Drop the redundant index to save space and improve write performance.',
                    'sql_example' => "DROP INDEX `{$redundant['redundant']}` ON `{$table['name']}`;",
                    'impact' => 'Reduced storage, faster writes'
                ];
            }

            if ($table['rows'] > 10000 && count($table['indexes']) < 2) {
                $tableRecommendations[] = [
                    'type' => 'insufficient_indexing',
                    'priority' => 'high',
                    'title' => 'Insufficient Indexing',
                    'description' => "Large table '{$table['name']}' has very few indexes, which may cause slow queries.",
                    'recommendation' => 'Analyze query patterns and add indexes on frequently queried columns.',
                    'sql_example' => "-- Analyze your queries and add indexes like:\n-- CREATE INDEX `idx_{$table['name']}_column` ON `{$table['name']}` (`column_name`);",
                    'impact' => 'Significantly improved query performance'
                ];
            }

            if (!empty($tableRecommendations)) {
                $recommendations[$table['name']] = $tableRecommendations;
            }
        }

        return $recommendations;
    }

    protected function calculatePerformanceImpact(array $tables): array
    {
        $impact = [
            'overall_score' => 0,
            'categories' => [
                'indexing' => ['score' => 0, 'issues' => 0],
                'structure' => ['score' => 0, 'issues' => 0],
                'efficiency' => ['score' => 0, 'issues' => 0]
            ],
            'critical_issues' => 0,
            'optimization_potential' => 'low'
        ];

        $totalTables = count($tables);
        $totalScore = 0;

        foreach ($tables as $table) {
            $tableScore = 100;

            if (!$this->hasPrimaryKey($table['indexes'])) {
                $tableScore -= 30;
                $impact['categories']['structure']['issues']++;
                $impact['critical_issues']++;
            }

            if ($table['rows'] > 10000 && count($table['indexes']) < 2) {
                $tableScore -= 25;
                $impact['categories']['indexing']['issues']++;
            }

            foreach ($table['indexes'] as $index) {
                if ($this->isIndexUnused($index, $table['index_usage'])) {
                    $tableScore -= 5;
                    $impact['categories']['efficiency']['issues']++;
                }
            }

            $totalScore += max(0, $tableScore);
        }

        $impact['overall_score'] = $totalTables > 0 ? round($totalScore / $totalTables) : 0;
        
        $impact['categories']['indexing']['score'] = max(0, 100 - ($impact['categories']['indexing']['issues'] * 15));
        $impact['categories']['structure']['score'] = max(0, 100 - ($impact['categories']['structure']['issues'] * 20));
        $impact['categories']['efficiency']['score'] = max(0, 100 - ($impact['categories']['efficiency']['issues'] * 10));

        if ($impact['overall_score'] < 60) {
            $impact['optimization_potential'] = 'high';
        } elseif ($impact['overall_score'] < 80) {
            $impact['optimization_potential'] = 'medium';
        } else {
            $impact['optimization_potential'] = 'low';
        }

        return $impact;
    }

    protected function calculateSummary(array $tables, array $recommendations): array
    {
        $summary = [
            'total_tables' => count($tables),
            'total_indexes' => 0,
            'unused_indexes' => 0,
            'missing_indexes' => 0,
            'redundant_indexes' => 0,
            'performance_score' => 0
        ];

        foreach ($tables as $table) {
            $summary['total_indexes'] += count($table['indexes']);
        }

        foreach ($recommendations as $tableRecs) {
            foreach ($tableRecs as $rec) {
                switch ($rec['type']) {
                    case 'unused_index':
                        $summary['unused_indexes']++;
                        break;
                    case 'missing_primary_key':
                    case 'missing_foreign_key_index':
                    case 'insufficient_indexing':
                        $summary['missing_indexes']++;
                        break;
                    case 'redundant_index':
                        $summary['redundant_indexes']++;
                        break;
                }
            }
        }

        return $summary;
    }

    protected function hasPrimaryKey(array $indexes): bool
    {
        foreach ($indexes as $index) {
            if ($index['name'] === 'PRIMARY') {
                return true;
            }
        }
        return false;
    }

    protected function hasIndexOnColumn(array $indexes, string $columnName): bool
    {
        foreach ($indexes as $index) {
            foreach ($index['columns'] as $column) {
                if ($column['name'] === $columnName) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function isIndexUnused(array $index, array $usageStats): bool
    {
        if (empty($usageStats)) {
            return false;
        }

        foreach ($usageStats as $stat) {
            if ($stat['INDEX_NAME'] === $index['name']) {
                return ($stat['COUNT_FETCH'] ?? 0) == 0;
            }
        }

        return false;
    }

    protected function findRedundantIndexes(array $indexes): array
    {
        $redundant = [];
        
        for ($i = 0; $i < count($indexes); $i++) {
            for ($j = $i + 1; $j < count($indexes); $j++) {
                if ($this->isIndexRedundant($indexes[$i], $indexes[$j])) {
                    $redundant[] = [
                        'primary' => $indexes[$i]['name'],
                        'redundant' => $indexes[$j]['name']
                    ];
                }
            }
        }

        return $redundant;
    }

    protected function isIndexRedundant(array $index1, array $index2): bool
    {
        $cols1 = array_column($index1['columns'], 'name');
        $cols2 = array_column($index2['columns'], 'name');

        if (count($cols1) <= count($cols2)) {
            return array_slice($cols2, 0, count($cols1)) === $cols1;
        }

        return false;
    }
}
