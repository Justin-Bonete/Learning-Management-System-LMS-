<?php
declare(strict_types=1);

spl_autoload_register(function (string $c): void {
    if (!str_starts_with($c, 'App\\')) return;
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, 4)) . '.php';
    if (is_file($f)) require $f;
});

use App\{Auth, HttpError, Request, Response, Router};

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

try {
    Auth::start();
    $r = Request::capture();
    $router = new Router();
    require __DIR__ . '/../src/routes.php';
    $router->dispatch($r);
} catch (HttpError $e) {
    Response::json($e->status, ['error' => ['message' => $e->getMessage(), 'fields' => $e->fields]]);
} catch (Throwable $e) {
    error_log((string)$e);
    Response::json(500, ['error' => ['message' => 'Something went wrong on the server.']]);
}
