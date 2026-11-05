<?php
declare(strict_types=1);
namespace App;

final class HttpError extends \Exception
{
    public function __construct(public int $status, string $message, public array $fields = [])
    {
        parent::__construct($message);
    }
}
