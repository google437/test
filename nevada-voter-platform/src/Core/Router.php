<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $opts];
    }

    public function get(string $p, callable|array $h, array $o = []): void { $this->add('GET', $p, $h, $o); }
    public function post(string $p, callable|array $h, array $o = []): void { $this->add('POST', $p, $h, $o); }

    public function dispatch(Request $req): void
    {
        foreach ($this->routes as [$method, $regex, $handler, $opts]) {
            if ($method !== $req->method || !preg_match($regex, $req->path, $m)) {
                continue;
            }
            $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $auth = $opts['auth'] ?? 'user';
            if ($auth !== 'none') {
                Auth::require($req, $auth, $opts['role'] ?? null);
            }
            if ($req->method === 'POST' && !($opts['csrf'] ?? true)) {
                // API endpoints authenticate via session cookie but use a header token instead of a form field.
                Csrf::verifyHeader();
            } elseif ($req->method === 'POST') {
                Csrf::verify();
            }
            if (is_array($handler)) {
                [$class, $fn] = $handler;
                (new $class())->$fn($req);
            } else {
                $handler($req);
            }
            return;
        }
        http_response_code(404);
        View::render('errors/404', ['title' => 'Not found']);
    }
}
