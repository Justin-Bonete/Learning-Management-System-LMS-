<?php
declare(strict_types=1);
namespace App;

final class Audit
{
    public static function log(?int $actor, string $action, string $type, ?int $id, array $meta = []): void
    {
        Db::run('INSERT INTO audit_logs(actor_id, action, entity_type, entity_id, metadata, ip) VALUES (?, ?, ?, ?, ?::jsonb, ?)',
            [$actor, $action, $type, $id, json_encode($meta), $_SERVER['REMOTE_ADDR'] ?? null]);
    }

    public static function notify(int $userId, string $type, array $payload): void
    {
        Db::run('INSERT INTO notifications(user_id, type, payload) VALUES (?, ?, ?::jsonb)', [$userId, $type, json_encode($payload)]);
    }
}
