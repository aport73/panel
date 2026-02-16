<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Database;
use Pterodactyl\Extensions\SqlDynDatabaseConnection;

class DatabaseHealthAnalyzer
{
    public function __construct(
        protected SqlDynDatabaseConnection $dynamic
    ) {}

    public function analyzeHealth(Database $database, bool $forceRefresh = false): array
    {
        $cacheKey = "db_health_{$database->server->uuid}_{$database->id}";
        
        if (!$forceRefresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            $cached['from_cache'] = true;
            $cached['cache_expires_at'] = Cache::get($cacheKey . '_expires');
            return $cached;
        }

        try {
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic');

            $analysis = [
                'database_id' => $database->id,
                'database_name' => $database->database,
                'server_uuid' => $database->server->uuid,
                'analyzed_at' => now()->toISOString(),
                'from_cache' => false,
                'alerts' => [],
                'metrics' => [],
                'recommendations' => [],
                'overall_health' => 'good',
                'health_score' => 100,
            ];

            $sizeAnalysis = $this->analyzeDatabaseSize($connection, $database->database);
            $analysis['metrics']['size'] = $sizeAnalysis;
            $this->addSizeAlerts($analysis, $sizeAnalysis);

            $tableAnalysis = $this->analyzeTableHealth($connection, $database->database);
            $analysis['metrics']['tables'] = $tableAnalysis;
            $this->addTableAlerts($analysis, $tableAnalysis);

            $indexAnalysis = $this->analyzeIndexHealth($connection, $database->database);
            $analysis['metrics']['indexes'] = $indexAnalysis;
            $this->addIndexAlerts($analysis, $indexAnalysis);

            $connectionAnalysis = $this->analyzeConnectionHealth($connection);
            $analysis['metrics']['connections'] = $connectionAnalysis;
            $this->addConnectionAlerts($analysis, $connectionAnalysis);

            $this->calculateOverallHealth($analysis);

            $expiresAt = now()->addHour();
            Cache::put($cacheKey, $analysis, $expiresAt);
            Cache::put($cacheKey . '_expires', $expiresAt->toISOString(), $expiresAt);

            return $analysis;

        } catch (\Exception $e) {
            Log::error('Database health analysis failed', [
                'database_id' => $database->id,
                'error' => $e->getMessage()
            ]);

            return [
                'database_id' => $database->id,
                'database_name' => $database->database,
                'server_uuid' => $database->server->uuid,
                'analyzed_at' => now()->toISOString(),
                'from_cache' => false,
                'error' => 'Analysis failed: ' . $e->getMessage(),
                'alerts' => [
                    [
                        'type' => 'analysis_error',
                        'severity' => 'critical',
                        'title' => 'Health Analysis Failed',
                        'description' => 'Could not analyze database health: ' . $e->getMessage(),
                        'recommendation' => 'Check database connectivity and permissions'
                    ]
                ],
                'overall_health' => 'unknown',
                'health_score' => 0,
            ];
        }
    }

    private function analyzeDatabaseSize($connection, string $databaseName): array
    {
        $tableCheckQuery = "SELECT COUNT(*) as table_count FROM information_schema.tables WHERE table_schema = ?";
        $tableCheck = $connection->selectOne($tableCheckQuery, [$databaseName]);

        if (($tableCheck->table_count ?? 0) === 0) {
            \Illuminate\Support\Facades\Log::warning('Database has no tables', [
                'database_name' => $databaseName
            ]);
            
            return [
                'total_size_mb' => 0,
                'data_size_mb' => 0,
                'index_size_mb' => 0,
                'free_space_mb' => 0,
                'table_count' => 0,
                'storage_efficiency' => 100
            ];
        }

        $sizeQuery = "
            SELECT 
                table_schema,
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb,
                ROUND(SUM(data_length) / 1024 / 1024, 2) AS data_mb,
                ROUND(SUM(index_length) / 1024 / 1024, 2) AS index_mb,
                ROUND(SUM(data_free) / 1024 / 1024, 2) AS free_mb,
                COUNT(*) as table_count
            FROM information_schema.tables 
            WHERE table_schema = ? 
            GROUP BY table_schema
        ";

        $result = $connection->selectOne($sizeQuery, [$databaseName]);

        if (!$result) {
            \Illuminate\Support\Facades\Log::warning('No result from size query', [
                'database_name' => $databaseName,
                'query' => $sizeQuery
            ]);
            
            return [
                'total_size_mb' => 0,
                'data_size_mb' => 0,
                'index_size_mb' => 0,
                'free_space_mb' => 0,
                'table_count' => 0,
                'storage_efficiency' => 100
            ];
        }

        $sizeMb = $result->size_mb ?? 0;
        $freeMb = $result->free_mb ?? 0;
        $dataMb = $result->data_mb ?? 0;
        $indexMb = $result->index_mb ?? 0;
        $tableCount = $result->table_count ?? 0;

        $finalResult = [
            'total_size_mb' => $sizeMb,
            'data_size_mb' => $dataMb,
            'index_size_mb' => $indexMb,
            'free_space_mb' => $freeMb,
            'table_count' => $tableCount,
            'storage_efficiency' => $sizeMb > 0 ? round((($sizeMb - $freeMb) / $sizeMb) * 100, 2) : 100
        ];

        return $finalResult;
    }

    private function analyzeTableHealth($connection, string $databaseName): array
    {
        $tablesQuery = "
            SELECT 
                table_name,
                table_rows,
                ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb,
                ROUND(data_length / 1024 / 1024, 2) AS data_mb,
                ROUND(index_length / 1024 / 1024, 2) AS index_mb,
                ROUND(data_free / 1024 / 1024, 2) AS free_mb,
                engine,
                table_collation
            FROM information_schema.tables 
            WHERE table_schema = ? AND table_type = 'BASE TABLE'
            ORDER BY (data_length + index_length) DESC
        ";

        $tables = $connection->select($tablesQuery, [$databaseName]);
        
        if (empty($tables)) {
            return [
                'total_tables' => 0,
                'largest_tables' => [],
                'empty_tables' => 0,
                'fragmented_tables' => 0,
                'engines_used' => [],
                'total_rows' => 0
            ];
        }
        
        $largestTables = array_slice($tables, 0, 5);
        $emptyTables = array_filter($tables, fn($t) => ($t->table_rows ?? 0) == 0);
        $fragmentedTables = array_filter($tables, fn($t) => ($t->free_mb ?? 0) > 10);

        return [
            'total_tables' => count($tables),
            'largest_tables' => $largestTables,
            'empty_tables' => count($emptyTables),
            'fragmented_tables' => count($fragmentedTables),
            'engines_used' => array_unique(array_column($tables, 'engine')),
            'total_rows' => array_sum(array_column($tables, 'table_rows'))
        ];
    }

    private function analyzeIndexHealth($connection, string $databaseName): array
    {
        $indexQuery = "
            SELECT 
                table_name,
                index_name,
                column_name,
                cardinality,
                index_type,
                non_unique
            FROM information_schema.statistics 
            WHERE table_schema = ?
            ORDER BY table_name, seq_in_index
        ";

        $indexes = $connection->select($indexQuery, [$databaseName]);
        
        $duplicateIndexes = $this->findDuplicateIndexes($indexes);
        $unusedIndexes = $this->findPotentiallyUnusedIndexes($indexes);

        return [
            'total_indexes' => count($indexes),
            'duplicate_indexes' => $duplicateIndexes,
            'potentially_unused' => $unusedIndexes,
            'index_types' => array_count_values(array_column($indexes, 'index_type'))
        ];
    }

    private function analyzeConnectionHealth($connection): array
    {
        try {
            $variables = $connection->select("SHOW VARIABLES LIKE '%connection%'");
            $status = $connection->select("SHOW STATUS LIKE '%connection%'");
            
            $maxConnections = collect($variables)->firstWhere('Variable_name', 'max_connections')->Value ?? 0;
            $threadsConnected = collect($status)->firstWhere('Variable_name', 'Threads_connected')->Value ?? 0;
            
            return [
                'max_connections' => (int) $maxConnections,
                'current_connections' => (int) $threadsConnected,
                'connection_usage_percent' => $maxConnections > 0 ? round(($threadsConnected / $maxConnections) * 100, 2) : 0
            ];
        } catch (\Exception $e) {
            return [
                'max_connections' => 0,
                'current_connections' => 0,
                'connection_usage_percent' => 0,
                'error' => 'Could not retrieve connection info'
            ];
        }
    }

    private function addSizeAlerts(array &$analysis, array $sizeData): void
    {
        if ($sizeData['total_size_mb'] > 1000) {
            $analysis['alerts'][] = [
                'type' => 'large_database',
                'severity' => $sizeData['total_size_mb'] > 5000 ? 'critical' : 'warning',
                'title' => 'Large Database Size',
                'description' => "Database size is {$sizeData['total_size_mb']} MB",
                'recommendation' => 'Consider archiving old data or optimizing storage'
            ];
        }

        if ($sizeData['storage_efficiency'] < 80) {
            $analysis['alerts'][] = [
                'type' => 'fragmentation',
                'severity' => 'warning',
                'title' => 'Storage Fragmentation Detected',
                'description' => "Storage efficiency is {$sizeData['storage_efficiency']}%",
                'recommendation' => 'Run OPTIMIZE TABLE on fragmented tables'
            ];
        }
    }

    private function addTableAlerts(array &$analysis, array $tableData): void
    {
        if ($tableData['empty_tables'] > 5) {
            $analysis['alerts'][] = [
                'type' => 'empty_tables',
                'severity' => 'low',
                'title' => 'Many Empty Tables',
                'description' => "{$tableData['empty_tables']} tables are empty",
                'recommendation' => 'Consider removing unused tables'
            ];
        }

        if ($tableData['fragmented_tables'] > 0) {
            $analysis['alerts'][] = [
                'type' => 'table_fragmentation',
                'severity' => 'medium',
                'title' => 'Fragmented Tables',
                'description' => "{$tableData['fragmented_tables']} tables have significant fragmentation",
                'recommendation' => 'Run OPTIMIZE TABLE on fragmented tables'
            ];
        }
    }

    private function addIndexAlerts(array &$analysis, array $indexData): void
    {
        if (count($indexData['duplicate_indexes']) > 0) {
            $analysis['alerts'][] = [
                'type' => 'duplicate_indexes',
                'severity' => 'medium',
                'title' => 'Duplicate Indexes Found',
                'description' => count($indexData['duplicate_indexes']) . ' duplicate indexes detected',
                'recommendation' => 'Remove duplicate indexes to improve performance'
            ];
        }
    }

    private function addConnectionAlerts(array &$analysis, array $connectionData): void
    {
        if ($connectionData['connection_usage_percent'] > 80) {
            $analysis['alerts'][] = [
                'type' => 'high_connection_usage',
                'severity' => $connectionData['connection_usage_percent'] > 95 ? 'critical' : 'warning',
                'title' => 'High Connection Usage',
                'description' => "{$connectionData['connection_usage_percent']}% of connections are in use",
                'recommendation' => 'Consider increasing max_connections or optimizing connection usage'
            ];
        }
    }

    private function calculateOverallHealth(array &$analysis): void
    {
        $score = 100;
        $criticalCount = 0;
        $warningCount = 0;

        foreach ($analysis['alerts'] as $alert) {
            switch ($alert['severity']) {
                case 'critical':
                    $score -= 25;
                    $criticalCount++;
                    break;
                case 'warning':
                case 'medium':
                    $score -= 15;
                    $warningCount++;
                    break;
                case 'low':
                    $score -= 5;
                    break;
            }
        }

        $score = max(0, $score);
        $analysis['health_score'] = $score;

        if ($criticalCount > 0 || $score < 50) {
            $analysis['overall_health'] = 'critical';
        } elseif ($warningCount > 0 || $score < 80) {
            $analysis['overall_health'] = 'warning';
        } else {
            $analysis['overall_health'] = 'good';
        }
    }

    private function findDuplicateIndexes(array $indexes): array
    {
        $indexGroups = [];
        foreach ($indexes as $index) {
            $key = $index->table_name . '_' . $index->column_name;
            $indexGroups[$key][] = $index;
        }

        return array_filter($indexGroups, fn($group) => count($group) > 1);
    }

    private function findPotentiallyUnusedIndexes(array $indexes): array
    {
        return array_filter($indexes, function($index) {
            return $index->cardinality === null || $index->cardinality == 0;
        });
    }
}
