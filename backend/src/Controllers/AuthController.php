<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Audit, Auth, Db, HttpError, Request, Response, V};

final class AuthController
{
    public static function login(Request $r): void
    {
        $email = V::email($r->body, 'email');
        $pw = V::str($r->body, 'password', 1, 200);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $fails = (int)Db::one("SELECT count(*) AS c FROM login_attempts WHERE email = ? AND succeeded = false AND attempted_at > now() - interval '15 minutes'", [$email])['c'];
        if ($fails >= 5) throw new HttpError(429, 'Too many failed attempts. Try again in 15 minutes.');

        $u = Db::one('SELECT id, password_hash, status FROM users WHERE email = ?', [$email]);
        $ok = $u ? ($u['status'] === 'active' && password_verify($pw, $u['password_hash'])) : (password_hash($pw, PASSWORD_DEFAULT) === '');
        Db::run('INSERT INTO login_attempts(email, ip, succeeded) VALUES (?, ?, ?)', [$email, $ip, $ok]);
        if (!$ok) {
            Audit::log($u['id'] ?? null, 'auth.login_failed', 'user', $u['id'] ?? null, ['email' => $email]);
            throw new HttpError(401, 'Email or password is incorrect.');
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Auth::forget();
        Audit::log((int)$u['id'], 'auth.login', 'user', (int)$u['id']);
        Response::json(200, ['user' => Auth::user(), 'csrf' => $_SESSION['csrf']]);
    }

    public static function me(Request $r): void
    {
        Response::json(200, ['user' => Auth::user(), 'csrf' => $_SESSION['csrf'] ?? null]);
    }

    public static function logout(Request $r): void
    {
        $_SESSION = [];
        session_destroy();
        Response::json(204);
    }
}
