<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension\Validation;

use Exception;
use Illuminate\Http\UploadedFile;
use Pterodactyl\Exceptions\Service\Database\DatabaseImportException;

trait FileValidator
{
    protected function validateSqlFile(UploadedFile $file): void
    {
        $this->validateFileBasics($file);
    }

    protected function validateFileBasics(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new DatabaseImportException('Invalid file upload');
        }

        if ($file->getSize() === 0) {
            throw new DatabaseImportException('Empty file uploaded');
        }

        $maxSize = 500 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            throw new DatabaseImportException('File size exceeds 500MB limit');
        }

        $filename = strtolower($file->getClientOriginalName());
        $allowedExtensions = ['.sql', '.gz'];
        
        $hasValidExtension = false;
        foreach ($allowedExtensions as $ext) {
            if (str_ends_with($filename, $ext)) {
                $hasValidExtension = true;
                break;
            }
        }
        
        if (!$hasValidExtension) {
            throw new DatabaseImportException('Only .sql and .gz files are supported');
        }
    }

    protected function secureFileRead(UploadedFile $file): string
    {
        throw new DatabaseImportException('File content validation disabled to prevent memory exhaustion - use force mode');
    }

    protected function validateFileContent(string $content, UploadedFile $file): void
    {
        throw new DatabaseImportException('File content validation disabled to prevent memory exhaustion - use force mode');
    }

    protected function validateSqlStructure(string $content): void
    {
        throw new DatabaseImportException('SQL structure validation disabled to prevent memory exhaustion - use force mode');
    }

    protected function detectMaliciousContent(string $content): void
    {
        throw new DatabaseImportException('Malicious content detection disabled to prevent memory exhaustion - use force mode');
    }

    protected function extractSqlStatements(string $content): array
    {
        throw new DatabaseImportException('SQL statement extraction disabled to prevent memory exhaustion - use force mode');
    }


    protected function decompressGzip(string $path): string
    {
        throw new DatabaseImportException('File decompression disabled to prevent memory exhaustion - use streaming import');
    }

    protected function decompressZip(string $path, string $filename): string
    {
        throw new DatabaseImportException('ZIP decompression disabled to prevent memory exhaustion - extract manually');
    }

    protected function decompressBzip2(string $path): string
    {
        throw new DatabaseImportException('BZIP2 decompression disabled to prevent memory exhaustion - extract manually');
    }
}