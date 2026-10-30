<?php
declare(strict_types=1);

namespace App\Services\Ingest;

/** Streams the first CSV/TXT entry out of a zip (or a plain CSV) without extracting it to disk. */
final class CsvReader
{
    /** @return resource */
    public static function open(string $path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('Cannot open zip');
            }
            $entry = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('/\.(csv|txt)$/i', $name) && !str_ends_with($name, '/')) {
                    $entry = $name;
                    break;
                }
            }
            $zip->close();
            if ($entry === null) {
                throw new \RuntimeException('Zip contains no .csv or .txt entry');
            }
            $fh = fopen('zip://' . $path . '#' . $entry, 'r');
        } else {
            $fh = fopen($path, 'r');
        }
        if (!$fh) {
            throw new \RuntimeException('Cannot open data file');
        }
        return $fh;
    }

    /** Map of normalized header -> column index. Normalizes case, whitespace and BOM. */
    public static function headerIndex(array $row): array
    {
        $idx = [];
        foreach ($row as $i => $h) {
            $h = strtoupper(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h) ?? ''));
            $h = preg_replace('/\s+/', ' ', $h);
            if ($h !== '') {
                $idx[$h] = $i;
            }
        }
        return $idx;
    }

    /** First matching alias in the header index, or null. */
    public static function col(array $idx, array $aliases): ?int
    {
        foreach ($aliases as $a) {
            $a = strtoupper($a);
            if (isset($idx[$a])) {
                return $idx[$a];
            }
            $alt = str_replace('_', ' ', $a);
            if (isset($idx[$alt])) {
                return $idx[$alt];
            }
        }
        return null;
    }

    public static function parseDate(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '' || $s === '0' || str_starts_with($s, '0000')) {
            return null;
        }
        foreach (['m/d/Y', 'm/d/Y H:i:s', 'm/d/Y H:i', 'Y-m-d', 'Y-m-d H:i:s', 'Ymd', 'n/j/Y', 'm-d-Y'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!' . $f, $s);
            if ($d && $d->format('Y') > 1900) {
                return $d->format('Y-m-d');
            }
        }
        $t = strtotime($s);
        return $t ? date('Y-m-d', $t) : null;
    }

    public static function parseDateTime(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        $t = strtotime($s);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }
}
