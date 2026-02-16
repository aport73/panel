<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Security;

use Exception;
use Pterodactyl\Exceptions\Services\Database\DatabaseImportException;

class FileLockManager
{
    private static array $locks = [];
    private const LOCK_TIMEOUT = 30;
    
    public static function acquireLock(string $resourceId): bool
    {
        $lockFile = sys_get_temp_dir() . '/pterodactyl_lock_' . md5($resourceId) . '.lock';
        
        if (file_exists($lockFile)) {
            $lockTime = filemtime($lockFile);
            if (time() - $lockTime < self::LOCK_TIMEOUT) {
                return false;
            } else {
                unlink($lockFile);
            }
        }
        
        $lockData = [
            'pid' => getmypid(),
            'timestamp' => time(),
            'resource' => $resourceId
        ];
        
        $success = file_put_contents($lockFile, json_encode($lockData), LOCK_EX);
        
        if ($success !== false) {
            self::$locks[$resourceId] = $lockFile;
            return true;
        }
        
        return false;
    }
    
    public static function releaseLock(string $resourceId): void
    {
        if (isset(self::$locks[$resourceId])) {
            $lockFile = self::$locks[$resourceId];
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
            unset(self::$locks[$resourceId]);
        }
    }
    
    public static function withLock(string $resourceId, callable $callback, int $maxRetries = 3)
    {
        $attempts = 0;
        
        while ($attempts < $maxRetries) {
            if (self::acquireLock($resourceId)) {
                try {
                    return $callback();
                } finally {
                    self::releaseLock($resourceId);
                }
            }
            
            $attempts++;
            if ($attempts < $maxRetries) {
                usleep(100000);
            }
        }
        
        throw new DatabaseImportException("Failed to acquire lock for resource: $resourceId after $maxRetries attempts");
    }
    
    public static function cleanup(): void
    {
        foreach (self::$locks as $resourceId => $lockFile) {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
        self::$locks = [];
    }
    
    public static function generateResourceId(string $operation, string $identifier): string
    {
        return $operation . '_' . md5($identifier . '_' . session_id());
    }
}
