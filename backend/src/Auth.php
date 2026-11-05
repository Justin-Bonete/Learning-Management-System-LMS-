<?php
declare(strict_types=1);
namespace App;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function start(): void
    {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => getenv('APP_ENV') === 'production', 'samesite' => 'Lax']);
        session_name('lms_sid');
        session_start();
    }

    public static function user(): ?array
    {
        if (self::$loaded) return self::$user;
        self::$loaded = true;
        $id = $_SESSION['uid'] ?? null;
        $u = $id ? Db::one('SELECT id, full_name, email, status FROM users WHERE id = ?', [$id]) : null;
        if ($u && $u['status'] === 'active') {
            $u['roles'] = array_column(Db::all('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$u['id']]), 'name');
            self::$user = $u;
        }
        return self::$user;
    }

    public static function forget(): void { self::$loaded = false; self::$user = null; }

    /** Middleware: must be signed in; state-changing calls also need the CSRF token. */
    public static function required(Request $r): void
    {
        if (!self::user()) throw new HttpError(401, 'Sign in to continue.');
        if (!in_array($r->method, ['GET', 'HEAD'], true) && !hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$r->header('X-CSRF-Token')))
            throw new HttpError(403, 'Missing or invalid CSRF token.');
    }

    public static function role(string ...$roles): \Closure
    {
        return function (Request $r) use ($roles): void {
            if (!array_intersect($roles, self::user()['roles'] ?? [])) throw new HttpError(403, 'Your role cannot do this.');
        };
    }
}
