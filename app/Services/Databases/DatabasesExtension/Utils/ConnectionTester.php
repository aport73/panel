<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Utils;

use Exception;
use Pterodactyl\Models\Database;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Extensions\DynamicDatabaseConnection;
use Pterodactyl\Exceptions\Service\Database\DatabaseExportException;

trait ConnectionTester
{
    public function testDatabaseConnection(Database $database): void
    {
        try {
            $host = $database->host;
            $decryptedPassword = $this->getDecryptedPassword($database);   
            $this->dynamic->setWithDatabaseCredentials('dynamic', $database);
            $connection = DB::connection('dynamic')->getPdo();
            $connection->query('SELECT 1');
            $connection->query("USE `{$database->database}`");
            
        } catch (Exception $e) {
            $host = $database->host;

            try {
                $decryptedPassword = $this->getDecryptedPassword($database);
            } catch (Exception $decryptError) {
                $decryptedPassword = 'DECRYPTION_FAILED: ' . $decryptError->getMessage();
            }
            
            logger()->error('Database connection test failed', [
                'database_name' => $database->database,
                'host' => $host->host,
                'port' => $host->port,
                'error_code' => $e->getCode()
            ]);
            
            throw new DatabaseExportException('Database connection failed: ' . $e->getMessage());
        }
    }

    public function outputEmptyDump(): string
    {
        $output = "-- Empty database dump\n";
        $output .= "-- Generated on " . date('Y-m-d H:i:s') . "\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $output .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $output .= "SET AUTOCOMMIT = 0;\n";
        $output .= "START TRANSACTION;\n\n";
        $output .= "-- No tables to export\n\n";
        $output .= "COMMIT;\n";
        $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        return $output;
    }
}