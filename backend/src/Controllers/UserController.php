<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Access, Audit, Auth, Db, HttpError, Request, Response, V};

final class UserController
{
    private const ROLES = ['administrator', 'instructor', 'learner', 'support'];

    public static function index(Request $r): void
    {
        [$per, $off, $page] = V::page($r->query);
        $q = trim((string)($r->query['q'] ?? ''));
        $where = '';
        $p = [];
        if ($q !== '') { $where = 'WHERE u.full_name ILIKE ? OR u.email ILIKE ?'; $like = '%' . addcslashes($q, '%_\\') . '%'; $p = [$like, $like]; }
        $rows = Db::all("SELECT u.id, u.full_name, u.email, u.status, COALESCE(string_agg(r.name, ',' ORDER BY r.name), '') AS roles
            FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
            $where GROUP BY u.id ORDER BY u.id LIMIT ? OFFSET ?", [...$p, $per, $off]);
        foreach ($rows as &$u) $u['roles'] = $u['roles'] === '' ? [] : explode(',', $u['roles']);
        Response::json(200, ['data' => $rows, 'page' => $page, 'per_page' => $per]);
    }

    public static function create(Request $r): void
    {
        $name = V::str($r->body, 'full_name', 2, 160);
        $email = V::email($r->body, 'email');
        $pw = V::str($r->body, 'password', 12, 200);
        $roles = $r->body['roles'] ?? ['learner'];
        if (!is_array($roles) || !$roles || array_diff($roles, self::ROLES)) throw new HttpError(422, 'Please fix the highlighted fields.', ['roles' => 'Choose from: ' . implode(', ', self::ROLES)]);
        $hash = password_hash($pw, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
        try {
            $id = Db::tx(function () use ($name, $email, $hash, $roles, $r) {
                $id = (int)Db::one('INSERT INTO users(full_name, email, password_hash) VALUES (?, ?, ?) RETURNING id', [$name, $email, $hash])['id'];
                foreach (array_unique($roles) as $role) Db::run('INSERT INTO user_roles(user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$id, $role]);
                Audit::log(Auth::user()['id'], 'user.created', 'user', $id, ['roles' => $roles]);
                return $id;
            });
        } catch (\Throwable $e) {
            if (Db::isDuplicate($e)) throw new HttpError(409, 'An account with this email already exists.', ['email' => 'Already in use.']);
            throw $e;
        }
        Response::json(201, ['id' => $id]);
    }

    public static function setStatus(Request $r): void
    {
        $id = $r->id();
        $status = V::str($r->body, 'status', 6, 20);
        if (!in_array($status, ['active', 'deactivated'], true)) throw new HttpError(422, 'Please fix the highlighted fields.', ['status' => 'Use active or deactivated.']);
        $me = Auth::user();
        if ($id === (int)$me['id']) throw new HttpError(422, 'You cannot change your own account status.');
        if (!Db::run('UPDATE users SET status = ?, updated_at = now() WHERE id = ?', [$status, $id])) throw new HttpError(404, 'User not found.');
        Audit::log((int)$me['id'], 'user.' . $status, 'user', $id);
        Response::json(200, ['id' => $id, 'status' => $status]);
    }

    public static function audit(Request $r): void
    {
        [$per, $off, $page] = V::page($r->query);
        $rows = Db::all('SELECT a.id, a.created_at, a.action, a.entity_type, a.entity_id, a.metadata, u.full_name AS actor
            FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT ? OFFSET ?', [$per, $off]);
        foreach ($rows as &$x) $x['metadata'] = json_decode($x['metadata'], true);
        Response::json(200, ['data' => $rows, 'page' => $page, 'per_page' => $per]);
    }
}
