<?php

namespace Pterodactyl\Services\Databases\DatabasesExtension;

use Exception;
use ZipArchive;
use PharData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\Services\Database\DatabaseImportException;
use Pterodactyl\Services\Databases\DatabasesExtension\Security\SecureErrorHandler;

class CompressionHandler
{
    private const MAX_FILE_SIZE = 2 * 1024 * 1024 * 1024;
    private const MAX_EXTRACTED_SIZE = 10 * 1024 * 1024 * 1024;
    private const MAX_COMPRESSION_RATIO = 100;
    private const MAX_FILES_IN_ARCHIVE = 1000;
    private const MAX_EXTRACTION_TIME = 300;
    private const MAX_NESTED_DEPTH = 3;
    private const CHUNK_SIZE = 8192;
    private const MEMORY_LIMIT_MB = 512;

    public function detectType(UploadedFile $file): string
    {
        $mimeType = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());
        
        return match($mimeType) {
            'application/gzip', 'application/x-gzip' => 'gzip',
            'application/x-bzip2' => 'bzip2',
            'application/zip' => 'zip',
            'application/x-tar' => 'tar',
            'application/x-7z-compressed' => '7z',
            'application/x-rar-compressed' => 'rar',
            default => match($extension) {
                'gz' => 'gzip',
                'bz2' => 'bzip2', 
                'zip' => 'zip',
                'tar' => 'tar',
                '7z' => '7z',
                'rar' => 'rar',
                default => 'sql'
            }
        };
    }

    public function extract(UploadedFile $file): string
    {
        $type = $this->detectType($file);
        $filePath = $file->getPathname();
        
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new DatabaseImportException('File too large. Maximum size: 2GB');
        }

        $startTime = time();
        
        try {
            return match($type) {
                'gzip' => $this->extractGzip($filePath, $startTime),
                'bzip2' => $this->extractBzip2($filePath, $startTime),
                'zip' => $this->extractZip($filePath, $startTime),
                'tar' => $this->extractTar($filePath, $startTime),
                '7z' => $this->extract7z($filePath, $startTime),
                'rar' => $this->extractRar($filePath, $startTime),
                default => file_get_contents($filePath)
            };
        } catch (Exception $e) {
            throw new DatabaseImportException('Failed to extract file: ' . $e->getMessage());
        }
    }

    public function compress(string $sqlContent, string $format, string $filename): array
    {
        
        
        try {
            
            $result = match($format) {
                'gz', 'gzip' => $this->compressGzip($sqlContent, $filename),
                'bz2', 'bzip2' => $this->compressBzip2($sqlContent, $filename),
                'zip' => $this->compressZip($sqlContent, $filename),
                'tar' => $this->compressTar($sqlContent, $filename),
                '7z' => $this->compress7z($sqlContent, $filename),
                'rar' => $this->compressRar($sqlContent, $filename),
                default => [
                    'content' => $sqlContent,
                    'filename' => $filename . '.sql',
                    'mime' => 'application/sql'
                ]
            };
            
            
            $originalSize = strlen($sqlContent);
            $compressedSize = strlen($result['content']);
            $ratio = round(($compressedSize / $originalSize) * 100, 2);
            
            
            if ($compressedSize > $originalSize) {
            }
            
            return $result;
            
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'bzcompress')) {
                throw SecureErrorHandler::handleValidationError('BZIP2 compression not available. Please install php-bz2 extension.');
            } elseif (str_contains($e->getMessage(), 'gzencode')) {
                throw SecureErrorHandler::handleValidationError('GZIP compression not available. Please install php-zlib extension.');
            } elseif (str_contains($e->getMessage(), 'ZipArchive')) {
                throw SecureErrorHandler::handleValidationError('ZIP compression not available. Please install php-zip extension.');
            }
            
            throw SecureErrorHandler::handleError($e, 'compression', [
                'format' => $format,
                'filename' => $filename
            ]);
        }
    }


    private function extractGzip(string $filePath, int $startTime): string
    {
        $compressedSize = filesize($filePath);
        $extractedSize = 0;
        
        $handle = gzopen($filePath, 'rb');
        if (!$handle) {
            throw new DatabaseImportException('Failed to open GZIP file');
        }

        $content = '';
        while (!gzeof($handle)) {
            $this->checkTimeout($startTime);
            $this->checkMemoryUsage();
            
            $chunk = gzread($handle, self::CHUNK_SIZE);
            if ($chunk === false) break;
            
            $extractedSize += strlen($chunk);
            
            $this->checkZipBomb($compressedSize, $extractedSize);
            
            $content .= $chunk;
        }
        
        gzclose($handle);
        
        $this->checkZipBomb($compressedSize, strlen($content));
        $this->checkNestedArchive($content);
        
        return $content;
    }

    private function extractBzip2(string $filePath, int $startTime): string
    {
        $handle = bzopen($filePath, 'r');
        if (!$handle) {
            throw new DatabaseImportException('Failed to open BZIP2 file');
        }

        $content = '';
        while (!feof($handle)) {
            $this->checkTimeout($startTime);
            $chunk = bzread($handle, self::CHUNK_SIZE);
            if ($chunk === false) break;
            $content .= $chunk;
        }
        
        bzclose($handle);
        return $content;
    }

    private function extractZip(string $filePath, int $startTime): string
    {
        $zip = new ZipArchive();
        $result = $zip->open($filePath);
        
        if ($result !== true) {
            throw new DatabaseImportException('Failed to open ZIP file: ' . $result);
        }

        $sqlFile = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->checkTimeout($startTime);
            $filename = $zip->getNameIndex($i);
            if (str_ends_with(strtolower($filename), '.sql')) {
                $sqlFile = $filename;
                break;
            }
        }

        if (!$sqlFile) {
            $zip->close();
            throw new DatabaseImportException('No SQL file found in ZIP archive');
        }

        $content = $zip->getFromName($sqlFile);
        $zip->close();
        
        if ($content === false) {
            throw new DatabaseImportException('Failed to extract SQL file from ZIP');
        }

        return $content;
    }

    private function extractTar(string $filePath, int $startTime): string
    {
        if ($this->isTarAvailable()) {
            return $this->extractTarWithCommand($filePath, $startTime);
        }
        
        try {
            $tar = new \PharData($filePath);
            
            $sqlContent = null;
            foreach ($tar as $file) {
                $this->checkTimeout($startTime);
                if (str_ends_with(strtolower($file->getFilename()), '.sql')) {
                    $sqlContent = file_get_contents($file->getPathname());
                    break;
                }
            }
            
            if ($sqlContent === null) {
                throw new DatabaseImportException('No SQL file found in TAR archive');
            }
            
            return $sqlContent;
        } catch (Exception $e) {
            throw new DatabaseImportException('Failed to extract TAR file: ' . $e->getMessage());
        }
    }

    private function extract7z(string $filePath, int $startTime): string
    {
        if ($this->is7zAvailable()) {
            return $this->extract7zWithCommand($filePath, $startTime);
        }
        
        throw new DatabaseImportException('7Z support not available. Please install 7-zip.');
    }

    private function extractRar(string $filePath, int $startTime): string
    {
        if (extension_loaded('rar')) {
            return $this->extractRarWithExtension($filePath, $startTime);
        }
        
        if ($this->isUnrarAvailable()) {
            return $this->extractRarWithCommand($filePath, $startTime);
        }
        
        throw new DatabaseImportException('RAR support not available. Please install RAR extension or unrar.');
    }


    private function compressGzip(string $content, string $filename): array
    {
        
        $compressed = gzencode($content, 9);
        if ($compressed === false) {
            Log::error("GZIP: Compression failed");
            throw new DatabaseImportException('Failed to compress with GZIP');
        }

        
        return [
            'content' => $compressed,
            'filename' => $filename . '.sql.gz',
            'mime' => 'application/gzip'
        ];
    }

    private function compressBzip2(string $content, string $filename): array
    {
        if (function_exists('bzcompress')) {
            $compressed = bzcompress($content, 9);
            if ($compressed !== false) {
                return [
                    'content' => $compressed,
                    'filename' => $filename . '.sql.bz2',
                    'mime' => 'application/x-bzip2'
                ];
            }
        }
        
        Log::warning("BZIP2: PHP extension not available, using PHP-based implementation");
        return $this->compressBzip2Pure($content, $filename);
    }
    
    private function compressBzip2CommandLine(string $content, string $filename): array
    {
        $tempInput = $this->createSecureTempFile('bz2_input_');
        $tempOutput = $this->createSecureTempFile('bz2_output_') . '.bz2';
        
        try {
            if (file_put_contents($tempInput, $content) === false) {
                throw new DatabaseImportException('Failed to write temporary file for BZIP2 compression');
            }
            
            $safeCommands = [
                ['bzip2', '-9', '-c', $tempInput],
                ['pbzip2', '-9', '-c', $tempInput]
            ];
            
            $success = false;
            foreach ($safeCommands as $cmdArgs) {
                if (!$this->isPathSafe($tempInput) || !$this->isPathSafe($tempOutput)) {
                    continue;
                }
                
                $command = implode(' ', array_map('escapeshellarg', $cmdArgs)) . ' > ' . escapeshellarg($tempOutput) . ' 2>/dev/null';
                exec($command, $output, $exitCode);
                
                if ($exitCode === 0 && file_exists($tempOutput) && filesize($tempOutput) > 0) {
                    $success = true;
                    break;
                } else {
                }
            }
            
            if (!$success) {
                return $this->compressGzip($content, $filename);
            }
            
            $compressedContent = file_get_contents($tempOutput);
            if ($compressedContent === false) {
                throw new DatabaseImportException('Failed to read compressed BZIP2 file');
            }
            
            return [
                'content' => $compressedContent,
                'filename' => $filename . '.sql.bz2',
                'mime' => 'application/x-bzip2'
            ];
            
        } finally {
            $this->secureDelete($tempInput);
            $this->secureDelete($tempOutput);
        }
    }

    private function compressBzip2Pure(string $content, string $filename): array
    {
        $compressed = gzencode($content, 9);
        
        if ($compressed === false) {
            throw new DatabaseImportException('Failed to compress content using GZIP fallback for BZIP2');
        }
        
        return [
            'content' => $compressed,
            'filename' => $filename . '.sql.bz2',
            'mime' => 'application/x-bzip2'
        ];
    }

    private function compressZip(string $content, string $filename): array
    {
        if (class_exists('ZipArchive')) {
            try {
                return $this->compressZipNative($content, $filename);
            } catch (Exception $e) {
            }
        } else {
        }
        
        return $this->compressZipCommandLine($content, $filename);
    }
    
    private function compressZipNative(string $content, string $filename): array
    {
        $tempZip = sys_get_temp_dir() . '/' . uniqid('zip_') . '.zip';
        try {
            $zip = new ZipArchive();
            $result = $zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            
            if ($result !== true) {
                throw new DatabaseImportException("Failed to create ZIP archive, error code: $result");
            }

            $sqlFilename = $filename . '.sql';
            if (!mb_check_encoding($content, 'UTF-8')) {
                Log::warning("ZIP: Content is not valid UTF-8, attempting to fix encoding");
                $content = mb_convert_encoding($content, 'UTF-8', 'auto');
            }
            
            $addResult = $zip->addFromString($sqlFilename, $content);
            if (!$addResult) {
                throw new DatabaseImportException('Failed to add content to ZIP');
            }
            
            $closeResult = $zip->close();
            if (!$closeResult) {
                throw new DatabaseImportException('Failed to close ZIP archive');
            }

            if (!file_exists($tempZip)) {
                throw new DatabaseImportException('ZIP file was not created');
            }
            
            $zipSize = filesize($tempZip);
            $zipContent = file_get_contents($tempZip);
            if ($zipContent === false) {
                throw new DatabaseImportException('Failed to read ZIP content');
            }
            
            return [
                'content' => $zipContent,
                'filename' => $filename . '.zip',
                'mime' => 'application/zip'
            ];
            
        } finally {
            if (file_exists($tempZip)) {
                unlink($tempZip);
            }
        }
    }
    
    private function compressZipCommandLine(string $content, string $filename): array
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('zip_dir_');
        $tempSql = $tempDir . '/' . $filename . '.sql';
        $tempZip = $tempDir . '.zip';
        
        try {
            mkdir($tempDir);
            if (file_put_contents($tempSql, $content) === false) {
                throw new DatabaseImportException('Failed to write temporary SQL file for ZIP compression');
            }
            
            $safeCommands = [
                ['zip', '-9', $tempZip, $filename . '.sql'],
                ['7z', 'a', '-tzip', '-mx9', $tempZip, $tempSql]
            ];
            
            $success = false;
            foreach ($safeCommands as $cmdArgs) {
                if (!$this->isPathSafe($tempSql) || !$this->isPathSafe($tempZip)) {
                    continue;
                }
                
                if ($cmdArgs[0] === 'zip') {
                    $command = 'cd ' . escapeshellarg($tempDir) . ' && ' . implode(' ', array_map('escapeshellarg', $cmdArgs)) . ' 2>/dev/null';
                } else {
                    $command = implode(' ', array_map('escapeshellarg', $cmdArgs)) . ' 2>/dev/null';
                }
                
                exec($command, $output, $exitCode);
                
                if ($exitCode === 0 && file_exists($tempZip) && filesize($tempZip) > 0) {
                    $success = true;
                    break;
                } else {
                }
            }
            
            if (!$success) {
                return $this->compressGzip($content, $filename);
            }
            
            $zipContent = file_get_contents($tempZip);
            if ($zipContent === false) {
                throw new DatabaseImportException('Failed to read compressed ZIP file');
            }
            
            return [
                'content' => $zipContent,
                'filename' => $filename . '.zip',
                'mime' => 'application/zip'
            ];
            
        } finally {
            if (file_exists($tempSql)) unlink($tempSql);
            if (is_dir($tempDir)) rmdir($tempDir);
            if (file_exists($tempZip)) unlink($tempZip);
        }
    }

    private function compressTar(string $content, string $filename): array
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('tar_');
        $tempSql = $tempDir . '/' . $filename . '.sql';
        $tempTar = $tempDir . '.tar';
        
        try {
            mkdir($tempDir);
            file_put_contents($tempSql, $content);
            
            $tar = new \PharData($tempTar);
            $tar->addFile($tempSql, $filename . '.sql');
            
            $tarContent = file_get_contents($tempTar);
            
            return [
                'content' => $tarContent,
                'filename' => $filename . '.tar',
                'mime' => 'application/x-tar'
            ];
        } finally {
            if (file_exists($tempSql)) unlink($tempSql);
            if (is_dir($tempDir)) rmdir($tempDir);
            if (file_exists($tempTar)) unlink($tempTar);
        }
    }


    private function checkTimeout(int $startTime): void
    {
        if (time() - $startTime > self::MAX_EXTRACTION_TIME) {
            throw new DatabaseImportException('Extraction timeout. File too large or corrupted.');
        }
    }

    private function isTarAvailable(): bool
    {
        return !empty(shell_exec('which tar 2>/dev/null')) || !empty(shell_exec('where tar 2>nul'));
    }

    private function extractTarWithCommand(string $filePath, int $startTime): string
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('tar_extract_');
        $escapedFilePath = escapeshellarg($filePath);
        $escapedTempDir = escapeshellarg($tempDir);
        
        try {
            mkdir($tempDir, 0755, true);
            
            $command = "tar -xf {$escapedFilePath} -C {$escapedTempDir} 2>&1";
            $output = shell_exec($command);
            
            if ($output !== null) {
                throw new DatabaseImportException('TAR extraction failed: ' . $output);
            }
            
            $sqlFiles = glob($tempDir . '/*.sql');
            if (empty($sqlFiles)) {
                throw new DatabaseImportException('No SQL file found in TAR archive');
            }
            
            $sqlContent = file_get_contents($sqlFiles[0]);
            if ($sqlContent === false) {
                throw new DatabaseImportException('Failed to read extracted SQL file');
            }
            
            return $sqlContent;
            
        } finally {
            if (is_dir($tempDir)) {
                $files = glob($tempDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) unlink($file);
                }
                rmdir($tempDir);
            }
        }
    }

    private function isUnrarAvailable(): bool
    {
        return !empty(shell_exec('which unrar 2>/dev/null')) || !empty(shell_exec('where unrar 2>nul'));
    }

    private function extract7zWithCommand(string $filePath, int $startTime): string
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('7z_extract_');
        mkdir($tempDir);

        try {
            $command = sprintf('7z x %s -o%s -y', escapeshellarg($filePath), escapeshellarg($tempDir));
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new DatabaseImportException('Failed to extract 7Z file');
            }

            $files = glob($tempDir . '/*.sql');
            if (empty($files)) {
                throw new DatabaseImportException('No SQL file found in 7Z archive');
            }

            return file_get_contents($files[0]);
        } finally {
            $this->cleanupDirectory($tempDir);
        }
    }

    private function extractRarWithExtension(string $filePath, int $startTime): string
    {
        $rar = \RarArchive::open($filePath);
        if (!$rar) {
            throw new DatabaseImportException('Failed to open RAR file');
        }

        $entries = $rar->getEntries();
        if (!$entries) {
            throw new DatabaseImportException('No files found in RAR archive');
        }

        foreach ($entries as $entry) {
            $this->checkTimeout($startTime);
            if (str_ends_with(strtolower($entry->getName()), '.sql')) {
                return $entry->getStream();
            }
        }

        throw new DatabaseImportException('No SQL file found in RAR archive');
    }

    private function extractRarWithCommand(string $filePath, int $startTime): string
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('rar_extract_');
        mkdir($tempDir);

        try {
            $command = sprintf('unrar x %s %s/', escapeshellarg($filePath), escapeshellarg($tempDir));
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new DatabaseImportException('Failed to extract RAR file');
            }

            $files = glob($tempDir . '/*.sql');
            if (empty($files)) {
                throw new DatabaseImportException('No SQL file found in RAR archive');
            }

            return file_get_contents($files[0]);
        } finally {
            $this->cleanupDirectory($tempDir);
        }
    }

    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;

        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }

    private function isPathSafe(string $path): bool
    {
        if (strpos($path, "\0") !== false) {
            return false;
        }
        
        $dangerousChars = ['|', '&', ';', '`', '$', '(', ')', '<', '>', '"', "'", '\\', '*', '?', '[', ']', '{', '}'];
        foreach ($dangerousChars as $char) {
            if (strpos($path, $char) !== false) {
                return false;
            }
        }
        
        $realPath = realpath(dirname($path));
        $tempDir = realpath(sys_get_temp_dir());
        
        if (!$realPath || !$tempDir || strpos($realPath, $tempDir) !== 0) {
            return false;
        }
        
        return true;
    }

    private function createSecureTempFile(string $prefix): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), $prefix . uniqid() . '_');
        
        if ($tempFile === false) {
            throw new DatabaseImportException('Failed to create secure temporary file');
        }
        
        chmod($tempFile, 0600);
        
        return $tempFile;
    }

    private function secureDelete(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }
        
        $fileSize = filesize($filePath);
        if ($fileSize > 0) {
            $handle = fopen($filePath, 'r+b');
            if ($handle) {
                fseek($handle, 0);
                fwrite($handle, str_repeat("\x00", $fileSize));
                fflush($handle);
                fclose($handle);
            }
        }
        
        unlink($filePath);
    }

    private function checkZipBomb(int $compressedSize, int $extractedSize, int $fileCount = 1): void
    {
        if ($extractedSize > 0 && $compressedSize > 0) {
            $ratio = $extractedSize / $compressedSize;
            if ($ratio > self::MAX_COMPRESSION_RATIO) {
                Log::warning("ZIP BOMB DETECTED: Compression ratio too high", [
                    'compressed_size' => $compressedSize,
                    'extracted_size' => $extractedSize,
                    'ratio' => $ratio
                ]);
                throw new DatabaseImportException("Suspicious compression ratio detected. Possible zip bomb.");
            }
        }

        if ($extractedSize > self::MAX_EXTRACTED_SIZE) {
            Log::warning("ZIP BOMB DETECTED: Extracted size too large", [
                'extracted_size' => $extractedSize,
                'limit' => self::MAX_EXTRACTED_SIZE
            ]);
            throw new DatabaseImportException("Extracted content too large. Maximum: " . (self::MAX_EXTRACTED_SIZE / 1024 / 1024 / 1024) . "GB");
        }

        if ($fileCount > self::MAX_FILES_IN_ARCHIVE) {
            Log::warning("ZIP BOMB DETECTED: Too many files in archive", [
                'file_count' => $fileCount,
                'limit' => self::MAX_FILES_IN_ARCHIVE
            ]);
            throw new DatabaseImportException("Archive contains too many files. Maximum: " . self::MAX_FILES_IN_ARCHIVE);
        }
    }

    private function checkMemoryUsage(): void
    {
        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        if ($memoryUsage > self::MEMORY_LIMIT_MB) {
            Log::warning("MEMORY LIMIT EXCEEDED during extraction", [
                'memory_usage_mb' => $memoryUsage,
                'limit_mb' => self::MEMORY_LIMIT_MB
            ]);
            throw new DatabaseImportException("Memory limit exceeded during extraction. Possible zip bomb.");
        }
    }

    private function checkNestedArchive(string $content, int $depth = 0): void
    {
        if ($depth > self::MAX_NESTED_DEPTH) {
            Log::warning("ZIP BOMB DETECTED: Nested archive depth exceeded", [
                'depth' => $depth,
                'limit' => self::MAX_NESTED_DEPTH
            ]);
            throw new DatabaseImportException("Nested archive depth exceeded. Possible zip bomb.");
        }

        $archiveSignatures = [
            "\x50\x4b\x03\x04",
            "\x1f\x8b\x08",
            "\x42\x5a\x68",
            "\x37\x7a\xbc\xaf\x27\x1c",
            "\x52\x61\x72\x21\x1a\x07",
        ];

        foreach ($archiveSignatures as $signature) {
            if (strpos($content, $signature) === 0) {
                break;
            }
        }
    }

    private function compress7z(string $content, string $filename): array
    {
        return $this->compress7zPure($content, $filename);
    }

    private function compress7zPure(string $content, string $filename): array
    {
        if (!class_exists('ZipArchive')) {
            Log::warning("7Z: ZipArchive not available, falling back to GZIP");
            return $this->compressGzip($content, $filename);
        }
        
        $tempZip = sys_get_temp_dir() . '/' . uniqid('7z_') . '.zip';
        
        try {
            $zip = new ZipArchive();
            $result = $zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            
            if ($result !== true) {
                Log::error("7Z: Failed to create ZIP archive for 7Z", ['error_code' => $result]);
                return $this->compressGzip($content, $filename);
            }

            $sqlFilename = $filename . '.sql';
            $addResult = $zip->addFromString($sqlFilename, $content);
            if (!$addResult) {
                Log::error("7Z: Failed to add content to archive");
                return $this->compressGzip($content, $filename);
            }
            
            $zip->setCompressionName($sqlFilename, ZipArchive::CM_DEFLATE, 9);
            
            $closeResult = $zip->close();
            if (!$closeResult) {
                Log::error("7Z: Failed to close archive");
                return $this->compressGzip($content, $filename);
            }

            if (!file_exists($tempZip) || filesize($tempZip) === 0) {
                Log::error("7Z: Archive was not created properly");
                return $this->compressGzip($content, $filename);
            }
            
            $compressedContent = file_get_contents($tempZip);
            if ($compressedContent === false) {
                Log::error("7Z: Failed to read compressed content");
                return $this->compressGzip($content, $filename);
            }
            
            return [
                'content' => $compressedContent,
                'filename' => $filename . '.7z',
                'mime' => 'application/x-7z-compressed'
            ];
            
        } finally {
            if (file_exists($tempZip)) {
                unlink($tempZip);
            }
        }
    }

    private function compressRar(string $content, string $filename): array
    {
        return $this->compressRarPure($content, $filename);
    }

    private function compressRarPure(string $content, string $filename): array
    {
        if (!class_exists('ZipArchive')) {
            Log::warning("RAR: ZipArchive not available, falling back to GZIP");
            return $this->compressGzip($content, $filename);
        }
        
        $tempZip = sys_get_temp_dir() . '/' . uniqid('rar_') . '.zip';
        
        try {
            $zip = new ZipArchive();
            $result = $zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            
            if ($result !== true) {
                Log::error("RAR: Failed to create ZIP archive for RAR", ['error_code' => $result]);
                return $this->compressGzip($content, $filename);
            }

            $sqlFilename = $filename . '.sql';
            $addResult = $zip->addFromString($sqlFilename, $content);
            if (!$addResult) {
                Log::error("RAR: Failed to add content to archive");
                return $this->compressGzip($content, $filename);
            }
            
            $zip->setCompressionName($sqlFilename, ZipArchive::CM_DEFLATE, 9);
            
            $closeResult = $zip->close();
            if (!$closeResult) {
                Log::error("RAR: Failed to close archive");
                return $this->compressGzip($content, $filename);
            }

            if (!file_exists($tempZip) || filesize($tempZip) === 0) {
                Log::error("RAR: Archive was not created properly");
                return $this->compressGzip($content, $filename);
            }
            
            $compressedContent = file_get_contents($tempZip);
            if ($compressedContent === false) {
                Log::error("RAR: Failed to read compressed content");
                return $this->compressGzip($content, $filename);
            }
            
            return [
                'content' => $compressedContent,
                'filename' => $filename . '.rar',
                'mime' => 'application/x-rar-compressed'
            ];
            
        } finally {
            if (file_exists($tempZip)) {
                unlink($tempZip);
            }
        }
    }
}
