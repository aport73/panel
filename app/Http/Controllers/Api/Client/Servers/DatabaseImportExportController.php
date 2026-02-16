<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Models\Permission;
use Pterodactyl\Facades\Activity;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Pterodactyl\Services\Databases\DatabaseImportExportService;
use Pterodactyl\Services\Databases\DatabasesExtension\Analytics\DatabaseHealthAnalyzer;
use Pterodactyl\Services\Databases\DatabasesExtension\Analytics\IndexAnalyzer;
use Pterodactyl\Services\Databases\DatabasesExtension\Presets\DatabasePresetsService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Databases\GetDatabasesRequest;
use Pterodactyl\Exceptions\Service\Database\DatabaseImportException;
use Pterodactyl\Exceptions\Service\Database\DatabaseExportException;
use Pterodactyl\Http\Controllers\Api\Client\Servers\DatabaseExtension\DatabaseImportController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\DatabaseExtension\DatabaseExportController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\DatabaseExtension\DatabaseTableController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\DatabaseExtension\DatabaseContentController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\DatabaseExtension\DatabaseAnalysisController;

class DatabaseImportExportController extends ClientApiController
{
    public function __construct() {
        parent::__construct();
    }

    
    private function getImportController(): DatabaseImportController
    {
        return new DatabaseImportController(
            app(DatabaseImportExportService::class)
        );
    }

    private function getExportController(): DatabaseExportController
    {
        return new DatabaseExportController(
            app(DatabaseImportExportService::class)
        );
    }

    private function getTableController(): DatabaseTableController
    {
        return new DatabaseTableController(
            app(DatabaseImportExportService::class)
        );
    }

    private function getContentController(): DatabaseContentController
    {
        return new DatabaseContentController(
            app(DatabaseImportExportService::class)
        );
    }

    private function getAnalysisController(): DatabaseAnalysisController
    {
        return new DatabaseAnalysisController(
            app(DatabaseImportExportService::class),
            new \Pterodactyl\Services\Databases\DatabasesExtension\Analytics\DatabaseHealthAnalyzer(
                app(\Pterodactyl\Extensions\SqlDynDatabaseConnection::class)
            ),
            new \Pterodactyl\Services\Databases\DatabasesExtension\Analytics\IndexAnalyzer(
                app(\Pterodactyl\Extensions\SqlDynDatabaseConnection::class)
            ),
            new \Pterodactyl\Services\Databases\DatabasesExtension\Presets\DatabasePresetsService(
                app(\Pterodactyl\Extensions\SqlDynDatabaseConnection::class)
            )
        );
    }

    
    public function download(GetDatabasesRequest $request, Server $server, Database $database): StreamedResponse
    {
        return $this->getExportController()->download($request, $server, $database);
    }

    public function downloadSelective(GetDatabasesRequest $request, Server $server, Database $database): StreamedResponse
    {
        return $this->getExportController()->downloadSelective($request, $server, $database);
    }

    public function getAvailableFormats(GetDatabasesRequest $request, Server $server): JsonResponse
    {
        return $this->getExportController()->getAvailableFormats($request, $server);
    }

    public function testCompression(GetDatabasesRequest $request, Server $server): JsonResponse
    {
        return $this->getExportController()->testCompression($request, $server);
    }

    
    public function upload(Request $request, Server $server, Database $database): JsonResponse
    {
        return $this->getImportController()->upload($request, $server, $database);
    }

    public function analyzeFile(Request $request, Server $server, Database $database): JsonResponse
    {
        return $this->getImportController()->analyzeFile($request, $server, $database);
    }

    public function getImportPreview(Request $request, Server $server, Database $database): JsonResponse
    {
        return $this->getImportController()->getImportPreview($request, $server, $database);
    }

    public function analyzeExternalDatabase(Request $request, Server $server, Database $database): JsonResponse
    {
        return $this->getImportController()->analyzeExternalDatabase($request, $server, $database);
    }

    
    public function info(GetDatabasesRequest $request, Server $server, Database $database): array
    {
        return $this->getContentController()->info($request, $server, $database);
    }

    public function contents(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        return $this->getContentController()->contents($request, $server, $database);
    }

    public function tableData(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        return $this->getContentController()->tableData($request, $server, $database, $tableName);
    }

    public function insertRow(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        return $this->getContentController()->insertRow($request, $server, $database, $tableName);
    }

    public function updateRow(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        return $this->getContentController()->updateRow($request, $server, $database, $tableName);
    }

    public function deleteRow(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        return $this->getContentController()->deleteRow($request, $server, $database, $tableName);
    }

    
    public function createTable(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        return $this->getTableController()->createTable($request, $server, $database);
    }

    public function dropTable(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        return $this->getTableController()->dropTable($request, $server, $database, $tableName);
    }

    public function addColumn(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        return $this->getTableController()->addColumn($request, $server, $database, $tableName);
    }

    public function dropColumn(GetDatabasesRequest $request, Server $server, Database $database, string $tableName, string $columnName): JsonResponse
    {
        return $this->getTableController()->dropColumn($request, $server, $database, $tableName, $columnName);
    }

    
    public function searchDatabase(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        return $this->getAnalysisController()->searchDatabase($request, $server, $database);
    }

    public function searchTableData(GetDatabasesRequest $request, Server $server, Database $database, string $tableName): JsonResponse
    {
        return $this->getAnalysisController()->searchTableData($request, $server, $database, $tableName);
    }

    public function executeSQL(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        return $this->getAnalysisController()->executeSQL($request, $server, $database);
    }

    public function analyzeHealth(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        return $this->getAnalysisController()->analyzeHealth($request, $server, $database);
    }

    public function analyzeIndexes(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        return $this->getAnalysisController()->analyzeIndexes($request, $server, $database);
    }

    public function getPresets(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        return $this->getAnalysisController()->getPresets($request, $server, $database);
    }

    public function applyPresets(GetDatabasesRequest $request, Server $server, Database $database): JsonResponse
    {
        return $this->getAnalysisController()->applyPresets($request, $server, $database);
    }
}