<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/'): string
{
    return '/' . ltrim($path, '/');
}

function redirect(string $path, int $code = 302): never
{
    header('Location: ' . url($path), true, $code);
    exit;
}

function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function fmt_date(?string $d, string $f = 'M j, Y'): string
{
    if (!$d) {
        return '';
    }
    try {
        return (new DateTimeImmutable($d))->format($f);
    } catch (Throwable) {
        return (string) $d;
    }
}

function fmt_dt(?string $d): string
{
    return fmt_date($d, 'M j, Y g:i a');
}

function fmt_num(int|float|string|null $n): string
{
    return number_format((float) ($n ?? 0));
}

function party_label(?string $p): string
{
    return match (strtoupper((string) $p)) {
        'DEM' => 'Democrat', 'REP' => 'Republican', 'NP' => 'Nonpartisan', 'LIB' => 'Libertarian',
        'INA' => 'Independent American', 'OTH' => 'Other', default => (string) $p,
    };
}

function status_label(?string $s): string
{
    return match ((string) $s) {
        'A' => 'Active', 'I' => 'Inactive', 'P17' => 'Pre-registered', 'removed' => 'Removed from file', default => (string) $s,
    };
}

function vote_type_label(?string $t): string
{
    return match ((string) $t) {
        'P' => 'Election day', 'M' => 'Mail', 'E' => 'Early', 'F' => 'Federal write-in', 'Z' => 'Provisional', default => (string) $t,
    };
}

function result_label(?string $r): string
{
    return ucwords(str_replace('_', ' ', (string) $r));
}

function voter_address(array $v): string
{
    $parts = array_filter([
        trim(($v['res_street_num'] ?? '') . ' ' . ($v['res_direction'] ?? '') . ' ' . ($v['res_street_name'] ?? '') . ' ' . ($v['res_address_type'] ?? '')),
        ($v['res_unit'] ?? '') !== '' ? '#' . $v['res_unit'] : '',
    ]);
    $line = preg_replace('/\s+/', ' ', implode(' ', $parts));
    if (!empty($v['confidential']) && $line === '') {
        return 'Address withheld (confidential)';
    }
    return trim($line);
}

function age_from_year(?int $y): ?int
{
    return $y ? (int) date('Y') - $y : null;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(App\Core\Csrf::token()) . '">';
}
