<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers\DatabaseExtension;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\Permission;
use Pterodactyl\Facades\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Pterodactyl\Services\Databases\DatabaseImportExportService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Databases\GetDatabasesRequest;
use Pterodactyl\Exceptions\Service\Database\DatabaseExportException;

class DatabaseExportController extends ClientApiController
{
    public function __construct(
        private DatabaseImportExportService $importExportService
    ) {
        parent::__construct();
    }

    public function download(GetDatabasesRequest $request, Server $server, Database $database): StreamedResponse
    {
        if (!$request->user()->can(Permission::ACTION_DATABASE_VIEW_PASSWORD, $server)) {
            abort(403, 'You do not have permission to export databases.');
        }
        
        $format = $request->get('format', 'sql');
        $allowedFormats = ['sql', 'gz', 'bz2', 'zip', 'tar', '7z', 'rar'];
        
        if (!in_array($format, $allowedFormats)) {
            $format = 'sql';
        }

        Activity::event('server:database.download')
            ->subject($database)
            ->property('name', $database->database)
            ->property('format', $format)
            ->log();
        if ($format !== 'sql') {
            return $this->importExportService->exportDatabaseCompressed($database, $format);
        }

        return $this->importExportService->exportDatabase($database);
    }

    public function downloadSelective(GetDatabasesRequest $request, Server $server, Database $database): StreamedResponse
    {
        try {
            if (!$request->user()->can(Permission::ACTION_DATABASE_VIEW_PASSWORD, $server)) {
                Log::warning("SELECTIVE EXPORT: Permission denied", ['user_id' => $request->user()->id]);
                abort(403, 'You do not have permission to export databases.');
            }

            $request->validate([
                'selected_tables' => 'required|array|min:1',
                'selected_tables.*' => 'string',
                'selected_columns' => 'array',
                'format' => 'string|in:sql,gz,bz2,zip,tar,7z,rar',
            ]);

            $tables = $request->get('selected_tables');
            $columns = $request->get('selected_columns', []);
            $format = $request->get('format', 'sql');

            Activity::event('server:database.download-selective')
                ->subject($database)
                ->property('name', $database->database)
                ->property('tables', $tables)
                ->property('format', $format)
                ->log();

            $options = [
                'tables' => $tables,
                'columns' => $columns,
                'selective' => true
            ];
            
            if ($format !== 'sql') {
                return $this->importExportService->exportDatabaseCompressed($database, $format, $options);
            }

            return $this->importExportService->exportDatabase($database, $options);
            
        } catch (\Exception $e) {
            Log::error("SELECTIVE EXPORT: Exception occurred", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->stream(function() use ($e) {
                echo "-- Export failed: " . $e->getMessage() . "\n";
                echo "-- Please check the logs for more details\n";
            }, 200, [
                'Content-Type' => 'application/sql',
                'Content-Disposition' => 'attachment; filename="export_error.sql"',
            ]);
        }
    }

    public function getAvailableFormats(GetDatabasesRequest $request, Server $server): \Illuminate\Http\JsonResponse
    {
        $formats = ['sql'];
        $missing_extensions = [];
        
        if (function_exists('gzencode')) {
            $formats[] = 'gz';
        } else {
            $missing_extensions[] = 'php-zlib (for GZIP)';
        }
        
        if (function_exists('bzcompress')) {
            $formats[] = 'bz2';
        } else {
            $missing_extensions[] = 'php-bz2 (for BZIP2)';
        }
        
        if (class_exists('ZipArchive')) {
            $formats[] = 'zip';
        } else {
            $missing_extensions[] = 'php-zip (for ZIP)';
        }
        
        if (class_exists('PharData')) {
            $formats[] = 'tar';
        } else {
            $missing_extensions[] = 'php-phar (for TAR)';
        }
        
        $response = [
            'available_formats' => $formats,
            'total_formats' => count($formats)
        ];
        
        if (!empty($missing_extensions)) {
            $response['missing_extensions'] = $missing_extensions;
            $response['install_command'] = 'sudo apt-get install ' . implode(' ', array_map(fn($ext) => explode(' ', $ext)[0], $missing_extensions));
        }
        
        return response()->json($response);
    }

    public function testCompression(GetDatabasesRequest $request, Server $server): \Illuminate\Http\JsonResponse
    {
        $format = $request->get('format', 'gz');
        $testSql = "-- Test SQL Export\nCREATE TABLE test (id INT PRIMARY KEY, name VARCHAR(255));\nINSERT INTO test VALUES (1, 'Test Data');\n";
        
        try {
            $compressionHandler = new \Pterodactyl\Services\Databases\DatabasesExtension\CompressionHandler();
            $compressed = $compressionHandler->compress($testSql, $format, 'test_' . date('Y-m-d_H-i-s'));
            
            $compressedLength = strlen($compressed['content']);
            $ratio = round(($compressedLength / strlen($testSql)) * 100, 2);
            
            return response()->json([
                'success' => true,
                'original_length' => strlen($testSql),
                'compressed_length' => $compressedLength,
                'compression_ratio' => $ratio . '%',
                'filename' => $compressed['filename'],
                'mime_type' => $compressed['mime'],
                'first_bytes_hex' => bin2hex(substr($compressed['content'], 0, 16))
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

}
