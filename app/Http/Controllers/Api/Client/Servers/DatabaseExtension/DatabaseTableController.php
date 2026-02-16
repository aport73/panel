<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers\DatabaseExtension;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Database;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Databases\DatabaseImportExportService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Databases\GetDatabasesRequest;

class DatabaseTableController extends ClientApiController
{
    public function __construct(
        private DatabaseImportExportService $importExportService
    ) {
        parent::__construct();
    }

    public function createTable(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        try {
            $request->validate([
                'table_name' => 'required|string|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/|max:64',
                'columns' => 'required|array|min:1|max:20',
                'columns.*.name' => 'required|string|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/|max:64',
                'columns.*.type' => 'required|string|in:TINYINT,SMALLINT,MEDIUMINT,INT,BIGINT,DECIMAL,FLOAT,DOUBLE,BIT,DATE,TIME,DATETIME,TIMESTAMP,YEAR,CHAR,VARCHAR,TEXT,TINYTEXT,MEDIUMTEXT,LONGTEXT,BLOB,TINYBLOB,MEDIUMBLOB,LONGBLOB,BOOLEAN,BOOL,JSON,ENUM,SET',
                'columns.*.length' => 'nullable|integer|min:1|max:65535',
                'columns.*.precision' => 'nullable|integer|min:0|max:30',
                'columns.*.enum_values' => 'nullable|array|max:65535',
                'columns.*.enum_values.*' => 'string|max:255',
                'columns.*.set_values' => 'nullable|array|max:64',
                'columns.*.set_values.*' => 'string|max:255',
                'columns.*.nullable' => 'required|boolean',
                'columns.*.default' => 'nullable|string|max:1000',
                'columns.*.auto_increment' => 'required|boolean',
                'columns.*.primary_key' => 'required|boolean',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('CreateTable validation failed', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            throw $e;
        }

        try {
            $tableName = $request->get('table_name');
            $columns = $request->get('columns');

            $primaryKeyCount = collect($columns)->where('primary_key', true)->count();
            if ($primaryKeyCount === 0) {
                Log::error('CreateTable: No primary key column specified');
                return response()->json([
                    'error' => 'At least one column must be marked as primary key'
                ], Response::HTTP_BAD_REQUEST);
            }

            $autoIncrementCount = 0;
            foreach ($columns as $column) {
                if (isset($column['auto_increment']) && $column['auto_increment'] === true) {
                    $autoIncrementCount++;

                    if (isset($column['default']) && !empty(trim($column['default']))) {
                        Log::error('CreateTable: AUTO_INCREMENT column has default value', [
                            'column' => $column['name'],
                            'default' => $column['default']
                        ]);
                        return response()->json([
                            'error' => "AUTO_INCREMENT column '{$column['name']}' cannot have a default value"
                        ], Response::HTTP_BAD_REQUEST);
                    }

                    if (!isset($column['primary_key']) || $column['primary_key'] !== true) {
                        Log::error('CreateTable: AUTO_INCREMENT column is not a primary key', [
                            'column' => $column['name']
                        ]);
                        return response()->json([
                            'error' => "AUTO_INCREMENT column '{$column['name']}' must be defined as a primary key"
                        ], Response::HTTP_BAD_REQUEST);
                    }
                }
            }

            if ($autoIncrementCount > 1) {
                Log::error('CreateTable: Multiple AUTO_INCREMENT columns found', [
                    'count' => $autoIncrementCount
                ]);
                return response()->json([
                    'error' => 'Only one AUTO_INCREMENT column is allowed per table'
                ], Response::HTTP_BAD_REQUEST);
            }

            foreach ($columns as $index => $column) {
                $columnType = strtoupper($column['type']);

                if ($column['auto_increment'] && !in_array($columnType, ['INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'MEDIUMINT'])) {
                    Log::error('CreateTable: AUTO_INCREMENT used with non-integer type', [
                        'column' => $column['name'],
                        'type' => $columnType
                    ]);
                    return response()->json([
                        'error' => "AUTO_INCREMENT can only be used with integer column types. Column '{$column['name']}' is {$columnType}"
                    ], Response::HTTP_BAD_REQUEST);
                }

                if (isset($column['length'])) {
                    $needsLength = in_array($columnType, ['VARCHAR', 'CHAR', 'DECIMAL']);
                    $canHaveLength = in_array($columnType, ['VARCHAR', 'CHAR', 'DECIMAL', 'FLOAT', 'DOUBLE']);
                    
                    if (!$canHaveLength) {
                        Log::warning('CreateTable: Length specified for type that does not support it', [
                            'column' => $column['name'],
                            'type' => $columnType,
                            'length' => $column['length']
                        ]);
                    }
                }
            }
            
            $result = $this->importExportService->createTable($database, $tableName, $columns);
            
            if ($result['success']) {
                Activity::event('server:database.create-table')
                    ->subject($database)
                    ->property('table', $tableName)
                    ->property('columns_count', count($columns))
                    ->log();
                    
                return response()->json($result);
            } else {
                return response()->json($result, Response::HTTP_BAD_REQUEST);
            }
        } catch (Exception $e) {
            Log::error('CreateTable failed', [
                'database' => $database->database,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to create table: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function dropTable(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        $request->validate([
            'force' => 'boolean',
        ]);

        try {
            $force = $request->boolean('force', false);
            
            $result = $this->importExportService->dropTable($database, $tableName, $force);
            
            if ($result['success']) {
                Activity::event('server:database.drop-table')
                    ->subject($database)
                    ->property('table', $tableName)
                    ->log();
                    
                return response()->json($result);
            } else {
                return response()->json($result, Response::HTTP_BAD_REQUEST);
            }
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to drop table: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function addColumn(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/|max:64',
            'type' => 'required|string|in:TINYINT,SMALLINT,MEDIUMINT,INT,BIGINT,DECIMAL,FLOAT,DOUBLE,BIT,DATE,TIME,DATETIME,TIMESTAMP,YEAR,CHAR,VARCHAR,TEXT,TINYTEXT,MEDIUMTEXT,LONGTEXT,BLOB,TINYBLOB,MEDIUMBLOB,LONGBLOB,BOOLEAN,BOOL,JSON,ENUM,SET',
            'length' => 'nullable|integer|min:1|max:65535',
            'precision' => 'nullable|integer|min:0|max:30',
            'enum_values' => 'nullable|array|max:65535',
            'enum_values.*' => 'string|max:255',
            'set_values' => 'nullable|array|max:64',
            'set_values.*' => 'string|max:255',
            'nullable' => 'required|boolean',
            'default' => 'nullable|string|max:1000',
            'auto_increment' => 'boolean',
            'primary_key' => 'boolean',
            'after' => 'nullable|string|max:64',
        ]);

        try {
            $columnData = $request->all();
            
            $result = $this->importExportService->addColumn($database, $tableName, $columnData);
            
            if ($result['success']) {
                Activity::event('server:database.add-column')
                    ->subject($database)
                    ->property('table', $tableName)
                    ->property('column', $columnData['name'])
                    ->property('type', $columnData['type'])
                    ->log();
                    
                return response()->json($result);
            } else {
                return response()->json($result, Response::HTTP_BAD_REQUEST);
            }
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to add column: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function dropColumn(GetDatabasesRequest $request, Server $server, Database $database, string $tableName, string $columnName): JsonResponse
    {
        try {
            $result = $this->importExportService->dropColumn($database, $tableName, $columnName);
            
            if ($result['success']) {
                Activity::event('server:database.drop-column')
                    ->subject($database)
                    ->property('table', $tableName)
                    ->property('column', $columnName)
                    ->log();
                    
                return response()->json($result);
            } else {
                return response()->json($result, Response::HTTP_BAD_REQUEST);
            }
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to drop column: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}