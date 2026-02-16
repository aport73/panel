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
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Databases\GetDatabasesRequest;

class DatabaseContentController extends ClientApiController
{
    public function __construct(
        private DatabaseImportExportService $importExportService
    ) {
        parent::__construct();
    }

    public function info(GetDatabasesRequest $request, Server $server, Database $database): array
    {
        $info = $this->importExportService->getDatabaseInfo($database);
        
        return [
            'database' => $database->database,
            'table_count' => $info['table_count'] ?? 0,
            'size_mb' => $info['size_mb'] ?? 0,
            'error' => $info['error'] ?? null,
        ];
    }

    public function contents(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        if (!$request->user()->can(Permission::ACTION_DATABASE_VIEW_PASSWORD, $server)) {
            return response()->json([
                'error' => 'You do not have permission to view database contents.'
            ], 403);
        }
        
        try {
            $contents = $this->importExportService->getDatabaseContents($database);
            
            Activity::event('server:database.view-contents')
                ->subject($database)
                ->property('name', $database->database)
                ->log();
    
            $responseData = [
                'database' => $database->database,
                'tables' => $contents['tables'] ?? [],
                'total_records' => $contents['total_records'] ?? 0,
            ];
            
            $jsonTest = json_encode($responseData);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('JSON encoding failed for database contents', [
                    'database' => $database->database,
                    'json_error' => json_last_error_msg()
                ]);
                
                return response()->json([
                    'database' => $database->database,
                    'tables' => array_map(function($table) {
                        unset($table['sample_data']);
                        return $table;
                    }, $contents['tables'] ?? []),
                    'total_records' => $contents['total_records'] ?? 0,
                ]);
            }
            
            return response()->json($responseData);
        } catch (Exception $e) {
            Log::error('Database contents request failed', [
                'user_id' => $request->user()->id,
                'server_id' => $server->id,
                'database_id' => $database->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'database' => $database->database,
                'tables' => [],
                'total_records' => 0,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function tableData(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        if (!$request->user()->can(Permission::ACTION_DATABASE_VIEW_PASSWORD, $server)) {
            return response()->json([
                'error' => 'You do not have permission to view database table data.'
            ], 403);
        }
        
        $request->validate([
            'page' => 'integer|min:1|max:1000',
            'limit' => 'integer|min:1|max:100',
        ]);

        try {
            $page = (int) $request->get('page', 1);
            $limit = (int) $request->get('limit', 50);
            $data = $this->importExportService->getTableData($database, $tableName, $page, $limit);
            $primaryKeyCount = 0;
            foreach ($data['columns'] as $column) {
                if ($column['column_key'] === 'PRI') {
                    $primaryKeyCount++;
                }
            }
            $data['has_primary_key'] = $primaryKeyCount > 0;
            
            return response()->json($data);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve table data: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function insertRow(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        $request->validate([
            'row_data' => 'required|array|min:1',
        ]);

        try {
            $data = $request->get('row_data');

            if (count($data) > 50) {
                return response()->json([
                    'error' => 'Too many columns to insert. Maximum 50 columns allowed.'
                ], Response::HTTP_BAD_REQUEST);
            }
            
            $result = $this->importExportService->insertTableRow($database, $tableName, $data);
            
            if ($result['success']) {
                Activity::event('server:database.insert-row')
                    ->subject($database)
                    ->property('table', $tableName)
                    ->property('affected_rows', $result['affected_rows'] ?? 1)
                    ->log();
                    
                return response()->json($result);
            } else {
                return response()->json($result, Response::HTTP_BAD_REQUEST);
            }
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to insert row: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function updateRow(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        $request->validate([
            'primary_key_values' => 'required|array',
            'update_data' => 'required|array|min:1',
            'force' => 'boolean',
        ]);

        try {
            $primaryKeyValues = $request->get('primary_key_values');
            $updateData = $request->get('update_data');
            $force = $request->boolean('force', false);

            if (count($updateData) > 50) {
                return response()->json([
                    'error' => 'Too many columns to update. Maximum 50 columns allowed.'
                ], Response::HTTP_BAD_REQUEST);
            }
            
            $result = $this->importExportService->updateTableRow($database, $tableName, $primaryKeyValues, $updateData, $force);
            
            if ($result['success']) {
                Activity::event('server:database.update-row')
                    ->subject($database)
                    ->property('table', $tableName)
                    ->property('affected_rows', $result['affected_rows'])
                    ->log();
                    
                return response()->json($result);
            } else {
                return response()->json($result, Response::HTTP_BAD_REQUEST);
            }
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to update row: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function deleteRow(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        $request->validate([
            'primary_key_values' => 'required|array',
            'force' => 'boolean',
        ]);

        try {
            $primaryKeyValues = $request->get('primary_key_values');
            $force = $request->boolean('force', false);
            
            $result = $this->importExportService->deleteTableRow($database, $tableName, $primaryKeyValues, $force);
            
            if ($result['success']) {
                Activity::event('server:database.delete-row')
                    ->subject($database)
                    ->property('table', $tableName)
                    ->property('affected_rows', $result['affected_rows'])
                    ->log();
                    
                return response()->json($result);
            } else {
                return response()->json($result, Response::HTTP_BAD_REQUEST);
            }
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to delete row: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}