<?php
declare(strict_types=1);
namespace App;

final class Request
{
    public array $params = [];

    public function __construct(public string $method, public string $path, public array $query, public array $body, private array $headers) {}

    public static function capture(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $raw = file_get_contents('php://input');
        $body = [];
        if ($raw !== '' && $raw !== false) {
            $d = json_decode($raw, true);
            if (!is_array($d)) throw new HttpError(400, 'Request body must be valid JSON.');
            $body = $d;
        } elseif ($_POST) {
            $body = $_POST; // multipart/form-data (file uploads)
        }
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', rtrim($path, '/') ?: '/', $_GET, $body, function_exists('getallheaders') ? getallheaders() : []);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) if (strcasecmp($k, $name) === 0) return $v;
        return null;
    }

    public function id(string $key = 'id'): int { return (int)($this->params[$key] ?? 0); }
}
