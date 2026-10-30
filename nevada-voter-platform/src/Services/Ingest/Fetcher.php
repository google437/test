<?php
declare(strict_types=1);

namespace App\Services\Ingest;

use App\Core\Config;
use App\Core\Log;

final class Fetcher
{
    private string $ua;
    private int $timeout;

    public function __construct()
    {
        $this->ua = Config::get('INGEST_USER_AGENT', 'NevadaVoterPlatform/0.1');
        $this->timeout = Config::int('INGEST_TIMEOUT_SECONDS', 600);
    }

    /** HEAD request: returns ['etag' => ?, 'last_modified' => ?, 'status' => int]. */
    public function head(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => $this->ua]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $out = ['etag' => null, 'last_modified' => null, 'status' => $status];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^etag:\s*(.+)$/i', trim($line), $m)) {
                $out['etag'] = trim($m[1]);
            } elseif (preg_match('/^last-modified:\s*(.+)$/i', trim($line), $m)) {
                $out['last_modified'] = trim($m[1]);
            }
        }
        return $out;
    }

    /** Download with retries and exponential backoff. Returns sha256 of the file. */
    public function download(string $url, string $dest, int $attempts = 4): string
    {
        $delay = 2;
        for ($i = 1; $i <= $attempts; $i++) {
            $fh = fopen($dest, 'w');
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $this->timeout, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_USERAGENT => $this->ua, CURLOPT_FAILONERROR => true]);
            $ok = curl_exec($ch);
            $err = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            fclose($fh);
            if ($ok && $status < 400 && filesize($dest) > 0) {
                return hash_file('sha256', $dest);
            }
            Log::warn("Download attempt $i failed for source (HTTP $status): $err");
            if ($i < $attempts) {
                sleep($delay);
                $delay *= 2;
            }
        }
        throw new \RuntimeException("Download failed after $attempts attempts (HTTP $status)");
    }
}
