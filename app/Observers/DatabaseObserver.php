<?php

namespace Pterodactyl\Observers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Database;

class DatabaseObserver
{
    public function deleted(Database $database): void
    {
        try {
            $this->cleanupExtensionData($database);

        } catch (\Exception $e) {
            Log::error('SQL Dashboard: Failed to cleanup extension data after database deletion', [
                'database_id' => $database->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function cleanupExtensionData(Database $database): void
    {
        $serverUuid = $database->server->uuid ?? null;
        $databaseId = (string) $database->id;

        if (DB::getSchemaBuilder()->hasTable('sql_history')) {
            DB::table('sql_history')
                ->where('server_uuid', $serverUuid)
                ->where('database_id', $databaseId)
                ->delete();
        }

        if (DB::getSchemaBuilder()->hasTable('database_statistics')) {
            DB::table('database_statistics')
                ->where('server_uuid', $serverUuid)
                ->where('database_id', $databaseId)
                ->delete();
        }

        if (DB::getSchemaBuilder()->hasTable('database_visualizations')) {
            DB::table('database_visualizations')
                ->where('server_uuid', $serverUuid)
                ->where('database_id', $databaseId)
                ->delete();
        }
    }
}
