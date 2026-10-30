<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    public array $params = [];
    private ?array $jsonBody = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $this->path = rtrim($path, '/') ?: '/';
    }

    public function get(string $k, mixed $d = null): mixed { return $_GET[$k] ?? $d; }
    public function post(string $k, mixed $d = null): mixed { return $_POST[$k] ?? $d; }
    public function str(string $k, string $d = ''): string { $v = $_POST[$k] ?? $_GET[$k] ?? $d; return is_string($v) ? trim($v) : $d; }
    public function int(string $k, ?int $d = null): ?int { $v = $_POST[$k] ?? $_GET[$k] ?? null; return ($v === null || $v === '') ? $d : (int) $v; }
    public function arr(string $k): array { $v = $_POST[$k] ?? $_GET[$k] ?? []; return is_array($v) ? array_values(array_filter($v, fn($x) => $x !== '')) : []; }
    public function bool(string $k): bool { $v = $_POST[$k] ?? $_GET[$k] ?? null; return $v !== null && $v !== '' && $v !== '0' && $v !== 'false'; }

    public function json(): array
    {
        if ($this->jsonBody === null) {
            $raw = file_get_contents('php://input') ?: '';
            $this->jsonBody = json_decode($raw, true) ?: [];
        }
        return $this->jsonBody;
    }

    public function isJson(): bool
    {
        return str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    }

    public function wantsJson(): bool
    {
        return $this->isJson() || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || str_starts_with($this->path, '/api/');
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
    }
}
