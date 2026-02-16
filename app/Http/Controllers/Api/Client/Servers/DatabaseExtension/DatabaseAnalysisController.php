<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers\DatabaseExtension;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\Permission;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Databases\DatabaseImportExportService;
use Pterodactyl\Services\Databases\DatabasesExtension\Analytics\DatabaseHealthAnalyzer;
use Pterodactyl\Services\Databases\DatabasesExtension\Analytics\IndexAnalyzer;
use Pterodactyl\Services\Databases\DatabasesExtension\Presets\DatabasePresetsService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Databases\GetDatabasesRequest;

class DatabaseAnalysisController extends ClientApiController
{
    public function __construct(
        private DatabaseImportExportService $importExportService,
        private DatabaseHealthAnalyzer $healthAnalyzer,
        private IndexAnalyzer $indexAnalyzer,
        private DatabasePresetsService $presetsService
    ) {
        parent::__construct();
    }

    public function searchDatabase(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        if (!$request->user()->can(Permission::ACTION_DATABASE_VIEW_PASSWORD, $server)) {
            return response()->json([
                'error' => 'You do not have permission to search database.'
            ], 403);
        }

        $request->validate([
            'search_term' => 'required|string|min:1|max:255',
            'tables' => 'array',
            'limit' => 'integer|min:1|max:100',
        ]);

        try {
            $searchTerm = $request->get('search_term');
            $tables = $request->get('tables', []);
            $limit = (int) $request->get('limit', 100);
            
            $results = $this->importExportService->searchDatabase($database, $searchTerm, $tables, $limit);
            
            Activity::event('server:database.search')
                ->subject($database)
                ->property('search_term', $searchTerm)
                ->property('results_count', count($results))
                ->log();
            
            return response()->json([
                'search_term' => $searchTerm,
                'results' => $results,
                'total_results' => count($results)
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Search failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function searchTableData(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        if (!$request->user()->can(Permission::ACTION_DATABASE_VIEW_PASSWORD, $server)) {
            return response()->json([
                'error' => 'You do not have permission to search table data.'
            ], 403);
        }

        $request->validate([
            'search_term' => 'string|min:1|max:255',
            'search_columns' => 'array',
            'search_columns.*' => 'string|max:64',
            'page' => 'integer|min:1|max:1000',
            'limit' => 'integer|min:1|max:100',
        ]);

        try {
            $searchTerm = $request->get('search_term', '');
            $searchColumns = $request->get('search_columns', []);
            $page = (int) $request->get('page', 1);
            $limit = (int) $request->get('limit', 50);
            
            $results = $this->importExportService->searchTableData($database, $tableName, $searchTerm, $searchColumns, $page, $limit);
            
            Activity::event('server:database.search-table')
                ->subject($database)
                ->property('table', $tableName)
                ->property('search_term', $searchTerm)
                ->property('results_count', $results['total'] ?? 0)
                ->log();
            
            return response()->json($results);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Table search failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function executeSQL(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        if (!$request->user()->can(Permission::ACTION_DATABASE_VIEW_PASSWORD, $server)) {
            return response()->json([
                'error' => 'You do not have permission to execute SQL.'
            ], 403);
        }

        $request->validate([
            'sql' => 'required|string|min:1|max:10000',
        ]);

        try {
            $sql = $request->get('sql');
            $isSelect = preg_match('/^\s*SELECT\s+/i', trim($sql));
            
            $result = $this->importExportService->executeRawSQL($database, $sql, $isSelect);
            
            Activity::event('server:database.execute-sql')
                ->subject($database)
                ->property('sql_length', strlen($sql))
                ->property('is_select', $isSelect)
                ->log();
            
            return response()->json($result);
        } catch (Exception $e) {
            Log::error('SQL execution failed', [
                'database' => $database->database,
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id
            ]);
            
            return response()->json([
                'error' => 'SQL execution failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function analyzeHealth(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        if (!$request->user()->can(Permission::ACTION_DATABASE_VIEW_PASSWORD, $server)) {
            return response()->json([
                'error' => 'You do not have permission to analyze database health.'
            ], 403);
        }

        try {
            $healthReport = $this->healthAnalyzer->analyzeHealth($database);
            
            Activity::event('server:database.analyze-health')
                ->subject($database)
                ->property('health_score', $healthReport['health_score'] ?? 0)
                ->log();
            
            return response()->json($healthReport);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Health analysis failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function analyzeIndexes(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        if (!$request->user()->can(Permission::ACTION_DATABASE_VIEW_PASSWORD, $server)) {
            return response()->json([
                'error' => 'You do not have permission to analyze database indexes.'
            ], 403);
        }

        try {
            $indexReport = $this->indexAnalyzer->analyzeIndexes($database);
            
            Activity::event('server:database.analyze-indexes')
                ->subject($database)
                ->property('indexes_analyzed', count($indexReport['indexes'] ?? []))
                ->log();
            
            return response()->json($indexReport);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Index analysis failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getPresets(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        try {
            $presets = $this->presetsService->getAvailablePresets();
            
            return response()->json([
                'presets' => $presets
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve presets: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function applyPresets(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        $request->validate([
            'presets' => 'required|array|min:1',
            'presets.*' => 'string|max:100',
            'selected_tables' => 'array',
        ]);

        try {
            $presetIds = $request->get('presets');
            $selectedTables = $request->get('selected_tables', []);
            
            $result = $this->presetsService->applyPresets($database, $presetIds, $selectedTables);
            
            if ($result['success']) {
                Activity::event('server:database.apply-presets')
                    ->subject($database)
                    ->property('preset_ids', $presetIds)
                    ->property('results', $result['results'])
                    ->log();
                    
                return response()->json($result);
            } else {
                return response()->json($result, Response::HTTP_BAD_REQUEST);
            }
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to apply presets: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}