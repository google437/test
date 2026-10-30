<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\Config;
use App\Core\Log;

/** Immutable raw-file archive: raw/{source}/{yyyy-mm-dd}/{sha256}.zip. Optionally synced to S3 by a shell command. */
final class Archive
{
    public static function store(string $source, string $tmpFile, string $sha): string
    {
        $base = Config::path('RAW_STORAGE_PATH', 'storage/raw');
        $ext = pathinfo($tmpFile, PATHINFO_EXTENSION) ?: 'zip';
        $dir = $base . '/' . $source . '/' . date('Y-m-d');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create archive dir');
        }
        $dest = $dir . '/' . $sha . '.' . $ext;
        if (!is_file($dest)) {
            if (!copy($tmpFile, $dest)) {
                throw new \RuntimeException('Cannot write archive file');
            }
            chmod($dest, 0440);
        }
        $cmd = Config::get('RAW_S3_SYNC_CMD');
        if ($cmd) {
            exec($cmd . ' 2>&1', $out, $rc);
            if ($rc !== 0) {
                Log::warn('RAW_S3_SYNC_CMD exited ' . $rc);
            }
        }
        return str_starts_with($dest, APP_ROOT) ? substr($dest, strlen(APP_ROOT) + 1) : $dest;
    }

    public static function absolute(string $rawPath): string
    {
        return str_starts_with($rawPath, '/') ? $rawPath : APP_ROOT . '/' . $rawPath;
    }
}
