<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Import;

use Exception;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Crypt;

class MemoryEfficientImportHelper
{
    public static function isPhpMyAdminDump(string $filePath): bool
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return false;
        }
        
        $linesRead = 0;
        $maxLinesToCheck = 30;
        $content = '';
        
        while (($line = fgets($handle)) !== false && $linesRead < $maxLinesToCheck) {
            $content .= $line;
            $linesRead++;
        }
        
        fclose($handle);
        
        $signatures = [
            '-- phpMyAdmin SQL Dump',
            '-- MariaDB dump',
            '-- MySQL dump',
            'Distrib 10.11.13-MariaDB',
            '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */',
            '/*!40101 SET NAMES utf8mb4 */',
        ];
        
        foreach ($signatures as $signature) {
            if (strpos($content, $signature) !== false) {
                return true;
            }
        }
        
        return false;
    }

    public static function getFileSize(string $filePath): int
    {
        return filesize($filePath) ?: 0;
    }

    public static function isPhpMyAdminParserAvailable(): bool
    {
        return class_exists('PhpMyAdmin\\SqlParser\\Parser');
    }

    public static function buildMysqlCommand(Database $database, DatabaseHost $host, string $sqlFile, string $mode): string
    {
        $password = Crypt::decrypt($host->password);
        
        return sprintf(
            'mysql --max-allowed-packet=1G --connect-timeout=30 --init-command="SET sql_mode=\'\'; SET FOREIGN_KEY_CHECKS=0;" -h %s -P %d -u %s -p%s %s < %s',
            escapeshellarg($host->host),
            $host->port,
            escapeshellarg($host->username),
            escapeshellarg($password),
            escapeshellarg($database->database),
            escapeshellarg($sqlFile)
        );
    }

    public static function executeMysqlCommand(string $command, int $timeout = 300): array
    {
        $result = Process::timeout($timeout)->run($command);
        
        return [
            'success' => $result->successful(),
            'output' => $result->output(),
            'error' => $result->errorOutput()
        ];
    }

    public static function forceGarbageCollection(): void
    {
        gc_collect_cycles();
        
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        
        clearstatcache();
    }

    public static function setMemoryEfficientSettings(): array
    {
        $originalSettings = [
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ];
        
        ini_set('memory_limit', '32M');
        
        ini_set('max_execution_time', 300);
        
        return $originalSettings;
    }

    public static function restoreSettings(array $originalSettings): void
    {
        foreach ($originalSettings as $setting => $value) {
            ini_set($setting, $value);
        }
    }

    public static function logMemoryUsage(string $context): void
    {
        $memoryUsage = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        
        error_log("MEMORY [{$context}]: Current: " . self::formatBytes($memoryUsage) . 
                 ", Peak: " . self::formatBytes($peakMemory));
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}