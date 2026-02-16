<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Security;

use Exception;
use Illuminate\Http\UploadedFile;
use Pterodactyl\Exceptions\Services\Database\DatabaseImportException;

class SecureFileValidator
{
    private const MAX_FILE_SIZE = 2 * 1024 * 1024 * 1024;
    private const ALLOWED_MIME_TYPES = [
        'application/sql',
        'text/plain',
        'text/x-sql',
        'application/gzip',
        'application/x-gzip',
        'application/zip',
        'application/x-zip-compressed',
        'application/x-bzip2',
        'application/x-bzip',
        'application/x-tar',
        'application/x-7z-compressed',
        'application/x-rar-compressed',
        'application/x-rar',
        'application/vnd.rar',
        'application/octet-stream'
    ];

    public function validateUploadedFile(UploadedFile $file, bool $force = false): void
    {
        $this->validateFileBasics($file, $force);
        $this->validateFileName($file);
        $this->validateFilePath($file);
        $this->validateMimeType($file);
        $this->validateMagicBytes($file);
        $this->validateFileContent($file);
    }

    private function validateFileBasics(UploadedFile $file, bool $force): void
    {
        if (!$file->isValid()) {
            throw new DatabaseImportException('File upload failed: ' . $file->getErrorMessage());
        }

        if ($file->getSize() === 0) {
            throw new DatabaseImportException('Empty file uploaded');
        }

        $maxSize = $force ? self::MAX_FILE_SIZE : (500 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            $limitMB = round($maxSize / 1024 / 1024);
            throw new DatabaseImportException("File size exceeds {$limitMB}MB limit");
        }
    }

    private function validateFileName(UploadedFile $file): void
    {
        $originalName = $file->getClientOriginalName();
        
        if (empty($originalName)) {
            throw new DatabaseImportException('Invalid filename provided');
        }

        if (str_contains($originalName, '..') || 
            str_contains($originalName, '/') || 
            str_contains($originalName, '\\') ||
            str_contains($originalName, ':')) {
            throw new DatabaseImportException('Filename contains invalid characters');
        }

        if (str_contains($originalName, "\0")) {
            throw new DatabaseImportException('Filename contains null bytes');
        }

        if (preg_match('/[\x00-\x1f\x7f-\x9f]/', $originalName)) {
            throw new DatabaseImportException('Filename contains control characters');
        }

        $this->validateFileExtension($originalName);
    }

    private function validateFileExtension(string $filename): void
    {
        $filename = strtolower($filename);
        $allowedExtensions = ['.sql', '.gz', '.bz2', '.zip', '.tar', '.7z', '.rar'];
        
        $hasValidExtension = false;
        foreach ($allowedExtensions as $ext) {
            if (str_ends_with($filename, $ext)) {
                $hasValidExtension = true;
                break;
            }
        }
        
        if (!$hasValidExtension) {
            throw new DatabaseImportException('Supported formats: .sql, .gz, .bz2, .zip, .tar, .7z, .rar');
        }

        $parts = explode('.', $filename);
        if (count($parts) > 3) { // filename.ext1.ext2 is max allowed
            throw new DatabaseImportException('Invalid file extension format');
        }

        $dangerousExtensions = ['.php', '.js', '.html', '.htm', '.exe', '.bat', '.sh', '.cmd'];
        foreach ($dangerousExtensions as $dangerousExt) {
            if (str_contains($filename, $dangerousExt)) {
                throw new DatabaseImportException('File type not allowed for security reasons');
            }
        }
    }

    private function validateFilePath(UploadedFile $file): void
    {
        $tempPath = $file->getPathname();
        
        if (empty($tempPath)) {
            throw new DatabaseImportException('Invalid file path');
        }

        $realPath = realpath($tempPath);
        
        if ($realPath === false) {
            throw new DatabaseImportException('File path could not be resolved');
        }

        $allowedPaths = [
            sys_get_temp_dir(),
            storage_path('app/uploads'),
            storage_path('app/tmp'),
        ];

        $isInAllowedPath = false;
        foreach ($allowedPaths as $allowedPath) {
            $realAllowedPath = realpath($allowedPath);
            if ($realAllowedPath && str_starts_with($realPath, $realAllowedPath)) {
                $isInAllowedPath = true;
                break;
            }
        }

        if (!$isInAllowedPath) {
            throw new DatabaseImportException('File is outside allowed directories');
        }

        if (!is_uploaded_file($tempPath)) {
            throw new DatabaseImportException('File was not uploaded via HTTP POST');
        }
    }

    private function validateMimeType(UploadedFile $file): void
    {
        $mimeType = $file->getMimeType();
        
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
            
            $allowedExtensions = ['sql', 'gz', 'bz2', 'zip', 'tar', '7z', 'rar'];
            if (!in_array($extension, $allowedExtensions)) {
                throw new DatabaseImportException('File type not supported: ' . $mimeType);
            }
        }
    }

    private function validateMagicBytes(UploadedFile $file): void
    {
        $filePath = $file->getPathname();
        $handle = fopen($filePath, 'rb');
        
        if (!$handle) {
            throw new DatabaseImportException('Cannot read uploaded file');
        }
        
        $magicBytes = fread($handle, 16);
        fclose($handle);
        
        if ($magicBytes === false) {
            throw new DatabaseImportException('Cannot read file magic bytes');
        }
        
        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        
        $validMagicBytes = $this->isValidMagicBytes($magicBytes, $extension);
        
        if (!$validMagicBytes) {
            throw new DatabaseImportException('File content does not match declared file type');
        }
    }

    private function isValidMagicBytes(string $magicBytes, string $extension): bool
    {
        $signatures = [
            'gz' => ["\x1f\x8b\x08"],
            'bz2' => ["\x42\x5a\x68"],
            'zip' => ["\x50\x4b\x03\x04", "\x50\x4b\x05\x06", "\x50\x4b\x07\x08"],
            '7z' => ["\x37\x7a\xbc\xaf\x27\x1c"],
            'rar' => ["\x52\x61\x72\x21\x1a\x07\x00", "\x52\x61\x72\x21\x1a\x07\x01\x00"],
            'tar' => []
        ];
        
        if ($extension === 'sql') {
            $sample = substr($magicBytes, 0, 16);
            for ($i = 0; $i < strlen($sample); $i++) {
                $byte = ord($sample[$i]);
                if ($byte < 32 && !in_array($byte, [9, 10, 13])) {
                    return false;
                }
            }
            return true;
        }
        
        if (!isset($signatures[$extension])) {
            return false;
        }
        
        if ($extension === 'tar') {
            return true;
        }
        
        foreach ($signatures[$extension] as $signature) {
            if (strpos($magicBytes, $signature) === 0) {
                return true;
            }
        }
        
        $compressedExtensions = ['gz', 'bz2', 'zip', '7z', 'rar', 'tar'];
        if (in_array($extension, $compressedExtensions)) {
            $firstByte = ord($magicBytes[0]);
            if ($firstByte >= 0x00 && $firstByte <= 0xFF) {
                return true;
            }
        }
        
        return false;
    }

    private function validateFileContent(UploadedFile $file): void
    {
        $filePath = $file->getPathname();
        
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new DatabaseImportException('Cannot read uploaded file for content validation');
        }
        
        $content = fread($handle, 8192);
        fclose($handle);
        
        if ($content === false) {
            throw new DatabaseImportException('Cannot read file content');
        }
        
        $maliciousPatterns = [
            "\x7f\x45\x4c\x46",
            "\x4d\x5a",
            "<?php",
            "<script",
            "#!/bin/",
            "#!/usr/bin/",
            "\x00\x00\x01\x00",
            "\x89\x50\x4e\x47",
            "\xff\xd8\xff",
        ];
        
        foreach ($maliciousPatterns as $pattern) {
            if (strpos($content, $pattern) !== false) {
                throw new DatabaseImportException('File contains potentially malicious content');
            }
        }
    }

    public function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);
        
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        if (strlen($filename) > 255) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = substr($name, 0, 255 - strlen($extension) - 1) . '.' . $extension;
        }
        
        if (empty($filename) || $filename === '.') {
            $filename = 'uploaded_file_' . time() . '.sql';
        }
        
        return $filename;
    }

    public function createSecureTempPath(string $originalFilename): string
    {
        $sanitizedName = $this->sanitizeFilename($originalFilename);
        $tempDir = sys_get_temp_dir();
        
        $uniqueName = uniqid('db_import_', true) . '_' . $sanitizedName;
        
        return $tempDir . DIRECTORY_SEPARATOR . $uniqueName;
    }
}
