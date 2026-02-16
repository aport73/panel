<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers\DatabaseExtension;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\Permission;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Databases\DatabaseImportExportService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Databases\GetDatabasesRequest;
use Pterodactyl\Exceptions\Service\Database\DatabaseImportException;

class DatabaseImportController extends ClientApiController
{
    public function __construct(
        private DatabaseImportExportService $importExportService
    ) {
        parent::__construct();
    }

    public function upload(Request $request, Server $server, Database $database): JsonResponse
    {
        if (!$request->user()->can(Permission::ACTION_DATABASE_VIEW_PASSWORD, $server)) {
            return response()->json([
                'error' => 'You do not have permission to import databases.'
            ], 403);
        }
        
        if ($request->input('import_type') === 'credentials') {
            logger()->info('Credentials import validation debug', [
                'import_type' => $request->input('import_type'),
                'mode' => $request->input('mode'),
                'force' => $request->input('force'),
                'source_credentials' => $request->input('source_credentials'),
                'all_input' => $request->all()
            ]);
        }

        $request->validate([
            'file' => [
                'required_if:import_type,file',
                'file',
                'max:102400',
                function ($attribute, $value, $fail) {
                    if (request()->input('import_type') !== 'file') {
                        return;
                    }

                    if (!$value instanceof UploadedFile) {
                        $fail('The file must be a valid uploaded file.');
                        return;
                    }

                    $filename = strtolower($value->getClientOriginalName());
                    $extension = strtolower($value->getClientOriginalExtension());

                    $allowedPatterns = [
                        '/\.sql\.(gz|gzip|bz2|rar)$/i', 
                        '/\.(sql|txt)$/i',           
                        '/\.(gz|gzip|zip|bz2|7z|tar|rar)$/i'    
                    ];
                    
                    $validExtension = false;
                    foreach ($allowedPatterns as $pattern) {
                        if (preg_match($pattern, $filename)) {
                            $validExtension = true;
                            break;
                        }
                    }
                    
                    if (!$validExtension) {
                        $fail('The file must be a file of type: sql, txt, gz, gzip, zip, bz2, rar, sql.gz, sql.gzip, sql.bz2, sql.rar.');
                        return;
                    }

                    $content = file_get_contents($value->getPathname());
                    if (empty(trim($content))) {
                        $fail('The uploaded file is empty.');
                        return;
                    }

                    if (stripos($content, '<!DOCTYPE html>') !== false || stripos($content, '<html>') !== false) {
                        $fail('Invalid file content detected. Please ensure you are uploading a valid SQL file.');
                        return;
                    }
                },
            ],
            'mode' => 'required|string|in:wipe,merge',
            'import_type' => 'required|string|in:file,credentials',
            'selective' => 'boolean',
            'selected_tables' => 'array',
            'selected_tables.*' => 'string',
            'selected_columns' => 'array',
            'source_credentials' => 'required_if:import_type,credentials|array',
            'source_credentials.host' => 'required_if:import_type,credentials|string|max:255',
            'source_credentials.port' => 'required_if:import_type,credentials|integer|min:1|max:65535',
            'source_credentials.username' => 'required_if:import_type,credentials|string|max:255',
            'source_credentials.password' => 'required_if:import_type,credentials|string|max:255',
            'source_credentials.database' => 'required_if:import_type,credentials|string|max:255',
        ]);

        $mode = $request->input('mode', 'merge');
        $force = $request->boolean('force', false);
        $importType = $request->input('import_type', 'file');
        $selective = $request->boolean('selective', false);
        $selectedTables = $request->input('selected_tables', []);
        $selectedColumns = $request->input('selected_columns', []);

        error_log("CONTROLLER DEBUG: Import parameters - Mode: {$mode}, Force: " . ($force ? 'true' : 'false') . ", Type: {$importType}");

        try {
            if ($importType === 'credentials') {
                $sourceCredentials = $request->input('source_credentials');
                
                $this->importExportService->importFromCredentials($database, $sourceCredentials, $mode, $force, $selective, $selectedTables, $selectedColumns);

                Activity::event('server:database.import-from-credentials')
                    ->subject($database)
                    ->property('name', $database->database)
                    ->property('mode', $mode)
                    ->property('source_host', $sourceCredentials['host'])
                    ->property('source_database', $sourceCredentials['database'])
                    ->log();

                return new JsonResponse([
                    'message' => 'Database imported successfully from external source'
                ], Response::HTTP_OK);
            } else {
                $file = $request->file('file');
                
                if (!$file) {
                    return response()->json([
                        'error' => 'File is required when import_type is file'
                    ], Response::HTTP_BAD_REQUEST);
                }

                $compatibilityReport = $this->importExportService->analyzeImportFile($file);
                
                if ($selective && !empty($selectedTables)) {
                    $this->importExportService->importSelectedTables($database, $file, $selectedTables, $selectedColumns, $mode);
                } else {
                    $this->importExportService->importDatabase($database, $file, $mode, $force);
                }

                Activity::event('server:database.upload')
                    ->subject($database)
                    ->property('name', $database->database)
                    ->property('mode', $mode)
                    ->property('filename', $file->getClientOriginalName())
                    ->property('compatibility_report', $compatibilityReport)
                    ->log();

                $response = [
                    'message' => 'Database imported successfully from file'
                ];

                if (!empty($compatibilityReport['applied_fixes']) || !empty($compatibilityReport['warnings'])) {
                    $response['compatibility_report'] = $compatibilityReport;
                }

                return new JsonResponse($response, Response::HTTP_OK);
            }
        } catch (DatabaseImportException $e) {
            Log::warning('Database import failed', [
                'database' => $database->database,
                'file' => $request->file('file')?->getClientOriginalName(),
                'mode' => $mode,
                'force' => $force,
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id
            ]);
            
            return response()->json([
                'error' => $e->getMessage(),
                'suggestions' => [
                    'Try using "Force Import" mode to skip problematic statements',
                    'Use "Wipe Database" mode if you want to replace existing data',
                    'Check if this is a complete database dump or partial export'
                ]
            ], Response::HTTP_BAD_REQUEST);
        } catch (Exception $e) {
            Log::error('Unexpected database import error', [
                'database' => $database->database,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()->id
            ]);
            
            return response()->json([
                'error' => 'An unexpected error occurred during import. Please check the server logs and try again.',
                'suggestions' => [
                    'Verify the SQL file is not corrupted',
                    'Try using "Force Import" mode',
                    'Contact administrator if the problem persists'
                ]
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function analyzeFile(Request $request, Server $server, Database $database): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:102400',
        ]);

        try {
            $file = $request->file('file');
            $analysis = $this->importExportService->analyzeImportFile($file);
            
            return response()->json($analysis);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to analyze file: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getImportPreview(Request $request, Server $server, Database $database): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:102400',
        ]);

        try {
            $file = $request->file('file');
            $preview = $this->importExportService->getImportPreview($file);
            
            return response()->json($preview);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to generate import preview: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function analyzeExternalDatabase(Request $request, Server $server, Database $database): JsonResponse
    {
        $request->validate([
            'source_credentials' => 'required|array',
            'source_credentials.host' => 'required|string|max:255',
            'source_credentials.port' => 'required|integer|min:1|max:65535',
            'source_credentials.username' => 'required|string|max:255',
            'source_credentials.password' => 'required|string|max:255',
            'source_credentials.database' => 'required|string|max:255',
        ]);

        try {
            $sourceCredentials = $request->input('source_credentials');
            $analysis = $this->importExportService->analyzeExternalDatabase($sourceCredentials);
            
            return response()->json($analysis);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to analyze external database: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}