<?php
declare(strict_types=1);
namespace App;

final class Response
{
    public static function json(int $status, mixed $data = null): never
    {
        http_response_code($status);
        if ($data !== null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        exit;
    }
}
