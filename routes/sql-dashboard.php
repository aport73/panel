<?php

use Pterodactyl\Http\Controllers\Api\Client\Servers\DatabaseImportExportController;

/*
|--------------------------------------------------------------------------
| SQL Dashboard Extension Routes
|--------------------------------------------------------------------------
|
| Add this line inside the databases group in routes/api-client.php:
| require_once __DIR__ . '/sql-dashboard.php';
|
| These routes will be automatically prefixed with /servers/{server}/databases
|
*/

Route::get('/{database}/download', [DatabaseImportExportController::class, 'download']);
Route::post('/{database}/upload', [DatabaseImportExportController::class, 'upload']);
Route::get('/{database}/info', [DatabaseImportExportController::class, 'info']);
Route::get('/{database}/contents', [DatabaseImportExportController::class, 'contents']);
Route::get('/{database}/table/{tableName}/data', [DatabaseImportExportController::class, 'tableData']);
Route::put('/{database}/table/{tableName}/row', [DatabaseImportExportController::class, 'updateRow']);
Route::post('/{database}/table/{tableName}/row', [DatabaseImportExportController::class, 'insertRow']);
Route::delete('/{database}/table/{tableName}/row', [DatabaseImportExportController::class, 'deleteRow']);
Route::post('/{database}/tables', [DatabaseImportExportController::class, 'createTable']);
Route::delete('/{database}/table/{tableName}', [DatabaseImportExportController::class, 'dropTable']);
Route::post('/{database}/table/{tableName}/columns', [DatabaseImportExportController::class, 'addColumn']);
Route::delete('/{database}/table/{tableName}/column/{columnName}', [DatabaseImportExportController::class, 'dropColumn']);
Route::post('/{database}/search', [DatabaseImportExportController::class, 'searchDatabase'])->middleware(['throttle:20,1']);
Route::post('/{database}/table/{tableName}/search', [DatabaseImportExportController::class, 'searchTableData'])->middleware(['throttle:30,1']);
Route::post('/{database}/execute-sql', [DatabaseImportExportController::class, 'executeSQL'])->middleware(['throttle:120,1']);
Route::post('/{database}/analyze-health', [DatabaseImportExportController::class, 'analyzeHealth'])->middleware(['throttle:10,1']);

// Selective Import/Export Routes
Route::post('/{database}/analyze-file', [DatabaseImportExportController::class, 'analyzeFile'])->middleware(['throttle:20,1']);
Route::post('/{database}/import-preview', [DatabaseImportExportController::class, 'getImportPreview'])->middleware(['throttle:20,1']);
Route::post('/{database}/analyze-external-database', [DatabaseImportExportController::class, 'analyzeExternalDatabase'])->middleware(['throttle:10,1']);
Route::get('/{database}/download-selective', [DatabaseImportExportController::class, 'downloadSelective']);
Route::get('/test-compression', [DatabaseImportExportController::class, 'testCompression']);

// Database Presets Routes
Route::get('/{database}/presets', [DatabaseImportExportController::class, 'getPresets']);
Route::post('/{database}/presets/apply', [DatabaseImportExportController::class, 'applyPresets'])->middleware(['throttle:5,1']);

// Index Analyzer Routes
Route::post('/{database}/analyze-indexes', [DatabaseImportExportController::class, 'analyzeHealth'])->middleware(['throttle:10,1']);
