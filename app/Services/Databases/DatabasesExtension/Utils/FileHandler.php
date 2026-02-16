<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Utils;

use Exception;
use Illuminate\Http\UploadedFile;
use Pterodactyl\Exceptions\Service\Database\DatabaseImportException;

trait FileHandler
{
    protected function getStreamingFileHandle(UploadedFile $file)
    {
        $path = $file->getPathname();
        $filename = strtolower($file->getClientOriginalName());

        if (str_ends_with($filename, '.gz')) {
            if (!function_exists('gzopen')) {
                throw new DatabaseImportException('GZIP support not available');
            }
            $handle = gzopen($path, 'rb');
            if ($handle === false) {
                throw new DatabaseImportException('Failed to open GZIP file');
            }
            return $handle;
        }

        if (str_ends_with($filename, '.zip')) {
            throw new DatabaseImportException('ZIP files not supported - extract manually to avoid memory exhaustion');
        }
        
        if (str_ends_with($filename, '.bz2')) {
            throw new DatabaseImportException('BZIP2 files not supported - extract manually to avoid memory exhaustion');
        }

        return fopen($path, 'rb');
    }

    protected function analyzeFullFile(UploadedFile $file): void
    {
        throw new DatabaseImportException('File analysis disabled to prevent memory exhaustion');
    }

    protected function extractSqlFromCompressedFile(UploadedFile $file): string
    {
        throw new DatabaseImportException('File extraction disabled to prevent memory exhaustion - use streaming only');
    }

    protected function createTempStreamForZip(UploadedFile $file, string $path)
    {
        throw new DatabaseImportException('ZIP extraction disabled to prevent memory exhaustion');
    }

    protected function detectCompressionType(string $filename, string $extension, string $mimeType, string $path): string
    {
        if (str_ends_with($filename, '.gz') || str_ends_with($filename, '.gzip')) {
            return 'gzip';
        }
        
        if (str_ends_with($filename, '.zip')) {
            return 'zip';
        }
        
        if (str_ends_with($filename, '.bz2') || str_ends_with($filename, '.bzip2')) {
            return 'bzip2';
        }
        
        return 'none';
    }
}