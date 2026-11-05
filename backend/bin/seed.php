<?php
// php bin/seed.php [--demo]   Creates the first administrator (and optional demo data). Safe to re-run.
declare(strict_types=1);
spl_autoload_register(fn($c) => is_file($f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, 4)) . '.php') ? require $f : null);
use App\Db;

$hash = fn(string $p) => password_hash($p, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
$mk = function (string $name, string $email, string $pw, string $role) use ($hash): int {
    if (strlen($pw) < 12) exit("Password for $email must be at least 12 characters.\n");
    $u = Db::one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($u) return (int)$u['id'];
    $id = (int)Db::one('INSERT INTO users(full_name, email, password_hash) VALUES (?, ?, ?) RETURNING id', [$name, $email, $hash($pw)])['id'];
    Db::run('INSERT INTO user_roles(user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$id, $role]);
    return $id;
};
$admin = $mk('System Administrator', strtolower(getenv('ADMIN_EMAIL') ?: ''), getenv('ADMIN_PASSWORD') ?: '', 'administrator');
echo "Administrator ready (id $admin)\n";

if (in_array('--demo', $argv, true)) {
    $pw = getenv('DEMO_PASSWORD') ?: '';
    $ins = $mk('Prof. Reyes', 'reyes@example.edu', $pw, 'instructor');
    $lrn = $mk('Maria Santos', 'maria@example.edu', $pw, 'learner');
    Db::run("INSERT INTO academic_terms(name, starts_at, ends_at, status) VALUES ('AY 2026-2027 Term 1', '2026-08-15', '2026-12-20', 'active') ON CONFLICT (name) DO NOTHING");
    Db::run("INSERT INTO courses(code, title, description, status, term_id) SELECT 'IT101', 'Web Systems Development', 'Demo course', 'published', id FROM academic_terms WHERE name = 'AY 2026-2027 Term 1' ON CONFLICT (code) DO NOTHING");
    $c = (int)Db::one("SELECT id FROM courses WHERE code = 'IT101'")['id'];
    Db::run("INSERT INTO course_sections(course_id, section_code, instructor_id) VALUES (?, 'A', ?) ON CONFLICT DO NOTHING", [$c, $ins]);
    $s = (int)Db::one("SELECT id FROM course_sections WHERE course_id = ? AND section_code = 'A'", [$c])['id'];
    Db::run('INSERT INTO enrollments(section_id, learner_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$s, $lrn]);
    if (!Db::one('SELECT 1 FROM modules WHERE course_id = ?', [$c])) {
        $m = (int)Db::one("INSERT INTO modules(course_id, title, position, published_at) VALUES (?, 'Module 1: How the web works', 1, now()) RETURNING id", [$c])['id'];
        Db::run("INSERT INTO lessons(module_id, title, body, position, published_at) VALUES (?, 'The HTTP request cycle', 'Requests, responses, status codes.', 1, now())", [$m]);
        Db::run("INSERT INTO assignments(course_id, title, instructions, due_at, max_points, published_at, created_by) VALUES (?, 'REST API design brief', 'Design five endpoints.', now() + interval '7 days', 50, now(), ?)", [$c, $ins]);
    }
    echo "Demo data ready: reyes@example.edu and maria@example.edu\n";
}
