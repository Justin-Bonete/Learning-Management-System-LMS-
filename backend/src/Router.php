<?php
declare(strict_types=1);
namespace App;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[0-9]+)', $pattern) . '$#';
        $this->routes[] = [$method, $re, $handler, $middleware];
    }

    public function dispatch(Request $r): void
    {
        $pathMatched = false;
        foreach ($this->routes as [$m, $re, $h, $mw]) {
            if (!preg_match($re, $r->path, $mt)) continue;
            $pathMatched = true;
            if ($m !== $r->method) continue;
            $r->params = array_filter($mt, 'is_string', ARRAY_FILTER_USE_KEY);
            foreach ($mw as $f) call_user_func($f, $r);
            call_user_func($h, $r);
            return;
        }
        throw new HttpError($pathMatched ? 405 : 404, $pathMatched ? 'Method not allowed.' : 'Not found.');
    }
}
