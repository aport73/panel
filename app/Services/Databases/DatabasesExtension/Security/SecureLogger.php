<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Security;

use Illuminate\Support\Facades\Log;

class SecureLogger
{
    private static array $sensitivePatterns = [
        'password', 'token', 'key', 'secret', 'credential', 'auth',
        'session', 'cookie', 'jwt', 'bearer', 'api_key'
    ];
    
    public static function warning(string $message, array $context = []): void
    {
        Log::warning($message, self::sanitizeContext($context));
    }
    
    public static function error(string $message, array $context = []): void
    {
        Log::error($message, self::sanitizeContext($context));
    }
    
    public static function logOperationStart(string $operation, string $database, array $metadata = []): string
    {
        $operationId = uniqid($operation . '_', true);
        
        return $operationId;
    }
    
    public static function logOperationComplete(string $operationId, string $operation, bool $success, array $metadata = []): void
    {
        $level = $success ? 'info' : 'error';
        $message = "Database operation " . ($success ? 'completed' : 'failed');
        
        if (!$success) {
            Log::$level($message, [
                'operation_id' => $operationId,
                'operation' => $operation,
                'success' => $success,
                'timestamp' => now()->toISOString(),
                'metadata' => self::sanitizeContext($metadata)
            ]);
        }
    }
    
    public static function logSecurityEvent(string $event, string $severity, array $context = []): void
    {
        Log::warning("Security event detected", [
            'event' => $event,
            'severity' => $severity,
            'timestamp' => now()->toISOString(),
            'ip_address' => request()->ip() ?? 'unknown',
            'user_agent' => request()->userAgent() ?? 'unknown',
            'context' => self::sanitizeContext($context)
        ]);
    }
    
    private static function sanitizeContext(array $context): array
    {
        $sanitized = [];
        
        foreach ($context as $key => $value) {
            $sanitized[$key] = self::sanitizeValue($key, $value);
        }
        
        return $sanitized;
    }
    
    private static function sanitizeValue(string $key, $value)
    {
        $lowerKey = strtolower($key);
        
        foreach (self::$sensitivePatterns as $pattern) {
            if (str_contains($lowerKey, $pattern)) {
                if (is_string($value)) {
                    return '[REDACTED:' . strlen($value) . '_chars]';
                } else {
                    return '[REDACTED:' . gettype($value) . ']';
                }
            }
        }
        
        if (in_array($lowerKey, ['sql', 'query', 'command']) && is_string($value)) {
            return substr($value, 0, 100) . (strlen($value) > 100 ? '...[TRUNCATED]' : '');
        }
        
        if (in_array($lowerKey, ['path', 'filepath', 'filename']) && is_string($value)) {
            return basename($value);
        }
        
        if (is_array($value)) {
            return self::sanitizeContext($value);
        }
        
        return $value;
    }
    
    public static function logFileOperation(string $operation, string $filename, int $size, string $type = 'unknown'): void
    {
    }
    
    private static function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        
        return round($size, 2) . ' ' . $units[$i];
    }
}
