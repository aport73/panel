<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Security;

use Exception;
use Throwable;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\Services\Database\DatabaseImportException;

class SecureErrorHandler
{
    public static function handleError(Throwable $e, string $context = '', array $metadata = []): Exception
    {
        $errorId = uniqid('ERR_', true);
        
        $sanitizedMetadata = self::sanitizeMetadata($metadata);
        
        Log::error("Database operation failed", [
            'error_id' => $errorId,
            'context' => $context,
            'error_type' => get_class($e),
            'error_code' => $e->getCode(),
            'metadata' => $sanitizedMetadata,
            'timestamp' => now()->toISOString()
        ]);
        
        $userMessage = self::getUserFriendlyMessage($e, $context, $errorId);
        
        return new DatabaseImportException($userMessage, $e->getCode(), $e);
    }
    
    private static function getUserFriendlyMessage(Throwable $e, string $context, string $errorId): string
    {
        $baseMessage = match($context) {
            'file_upload' => 'File upload failed',
            'file_validation' => 'File validation failed',
            'compression' => 'File compression/decompression failed',
            'database_import' => 'Database import failed',
            'database_export' => 'Database export failed',
            'sql_execution' => 'SQL execution failed',
            default => 'Operation failed'
        };
        
        if (str_contains($e->getMessage(), 'permission')) {
            $baseMessage .= ': Permission denied';
        } elseif (str_contains($e->getMessage(), 'connection')) {
            $baseMessage .= ': Connection error';
        } elseif (str_contains($e->getMessage(), 'timeout')) {
            $baseMessage .= ': Operation timed out';
        } elseif (str_contains($e->getMessage(), 'memory')) {
            $baseMessage .= ': Insufficient memory';
        } elseif (str_contains($e->getMessage(), 'disk space')) {
            $baseMessage .= ': Insufficient disk space';
        } else {
            $baseMessage .= ': Technical error occurred';
        }
        
        return $baseMessage . " (Error ID: {$errorId})";
    }
    
    private static function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];
        $sensitiveKeys = [
            'password', 'token', 'key', 'secret', 'credential',
            'sql', 'query', 'command', 'path', 'filename'
        ];
        
        foreach ($metadata as $key => $value) {
            $lowerKey = strtolower($key);
            $isSensitive = false;
            
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }
            
            if ($isSensitive) {
                if (is_string($value)) {
                    $sanitized[$key] = '[REDACTED:' . strlen($value) . '_chars]';
                } else {
                    $sanitized[$key] = '[REDACTED:' . gettype($value) . ']';
                }
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    public static function handleValidationError(string $message, array $context = []): Exception
    {
        return self::handleError(
            new Exception($message), 
            'file_validation', 
            $context
        );
    }
    
    public static function handleSqlError(Throwable $e, array $context = []): Exception
    {
        return self::handleError($e, 'sql_execution', $context);
    }
    
    public static function handleFileError(Throwable $e, string $operation, array $context = []): Exception
    {
        return self::handleError($e, $operation, $context);
    }
}
