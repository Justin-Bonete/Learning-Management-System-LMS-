<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Access, Audit, Auth, Db, HttpError, Request, Response, V};

final class CourseController
{
    public static function terms(Request $r): void { Response::json(200, ['data' => Db::all('SELECT * FROM academic_terms ORDER BY starts_at DESC')]); }

    public static function createTerm(Request $r): void
    {
        $b = $r->body;
        $name = V::str($b, 'name', 2, 80); $s = V::str($b, 'starts_at', 10, 10); $e = V::str($b, 'ends_at', 10, 10);
        if ($e <= $s) throw new HttpError(422, 'Please fix the highlighted fields.', ['ends_at' => 'Must be after the start date.']);
        try { $id = (int)Db::one('INSERT INTO academic_terms(name, starts_at, ends_at) VALUES (?, ?, ?) RETURNING id', [$name, $s, $e])['id']; }
        catch (\Throwable $x) { throw Db::isDuplicate($x) ? new HttpError(409, 'A term with this name exists.') : $x; }
        Audit::log(Auth::user()['id'], 'term.created', 'term', $id);
        Response::json(201, ['id' => $id]);
    }

    public static function index(Request $r): void
    {
        $u = Auth::user();
        if (Access::isAdmin($u)) $rows = Db::all('SELECT id, code, title, status FROM courses ORDER BY code');
        else $rows = Db::all("SELECT DISTINCT c.id, c.code, c.title, c.status FROM courses c JOIN course_sections s ON s.course_id = c.id
            LEFT JOIN enrollments e ON e.section_id = s.id AND e.status = 'active'
            WHERE c.status = 'published' AND (s.instructor_id = ? OR e.learner_id = ?) ORDER BY c.code", [$u['id'], $u['id']]);
        Response::json(200, ['data' => $rows]);
    }

    public static function create(Request $r): void
    {
        $b = $r->body;
        $code = strtoupper(V::str($b, 'code', 2, 40)); $title = V::str($b, 'title', 2, 200);
        $desc = V::str($b, 'description', 0, 5000, false); $term = V::int($b, 'term_id', false);
        try { $id = (int)Db::one('INSERT INTO courses(code, title, description, term_id) VALUES (?, ?, ?, ?) RETURNING id', [$code, $title, $desc, $term])['id']; }
        catch (\Throwable $x) { throw Db::isDuplicate($x) ? new HttpError(409, 'A course with this code exists.', ['code' => 'Already in use.']) : $x; }
        Audit::log(Auth::user()['id'], 'course.created', 'course', $id, ['code' => $code]);
        Response::json(201, ['id' => $id]);
    }

    public static function publish(Request $r): void
    {
        $id = $r->id(); Access::needCourse($id);
        Db::run("UPDATE courses SET status = 'published' WHERE id = ?", [$id]);
        Audit::log(Auth::user()['id'], 'course.published', 'course', $id);
        Response::json(200, ['id' => $id, 'status' => 'published']);
    }

    public static function show(Request $r): void
    {
        $u = Auth::user(); $id = $r->id(); $c = Access::needCourse($id);
        Access::needView($u, $id);
        $c['sections'] = Db::all('SELECT s.id, s.section_code, i.full_name AS instructor, (SELECT count(*) FROM enrollments e WHERE e.section_id = s.id AND e.status = \'active\') AS enrolled
            FROM course_sections s JOIN users i ON i.id = s.instructor_id WHERE s.course_id = ? ORDER BY s.section_code', [$id]);
        Response::json(200, $c);
    }

    public static function createSection(Request $r): void
    {
        $cid = $r->id(); Access::needCourse($cid);
        $code = V::str($r->body, 'section_code', 1, 20); $ins = V::int($r->body, 'instructor_id');
        if (!Db::one("SELECT 1 FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id JOIN users u ON u.id = ur.user_id WHERE u.id = ? AND u.status = 'active' AND ro.name = 'instructor'", [$ins]))
            throw new HttpError(422, 'Please fix the highlighted fields.', ['instructor_id' => 'Must be an active instructor.']);
        try { $id = (int)Db::one('INSERT INTO course_sections(course_id, section_code, instructor_id) VALUES (?, ?, ?) RETURNING id', [$cid, $code, $ins])['id']; }
        catch (\Throwable $x) { throw Db::isDuplicate($x) ? new HttpError(409, 'This section code already exists for the course.') : $x; }
        Audit::log(Auth::user()['id'], 'section.created', 'section', $id, ['course_id' => $cid, 'instructor_id' => $ins]);
        Response::json(201, ['id' => $id]);
    }

    public static function enroll(Request $r): void
    {
        $sid = $r->id();
        $sec = Db::one('SELECT id, course_id FROM course_sections WHERE id = ?', [$sid]) ?? throw new HttpError(404, 'Section not found.');
        $lid = V::int($r->body, 'learner_id');
        if (!Db::one("SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles ro ON ro.id = ur.role_id WHERE u.id = ? AND u.status = 'active' AND ro.name = 'learner'", [$lid]))
            throw new HttpError(422, 'Please fix the highlighted fields.', ['learner_id' => 'Must be an active learner.']);
        try { $id = (int)Db::one('INSERT INTO enrollments(section_id, learner_id) VALUES (?, ?) RETURNING id', [$sid, $lid])['id']; }
        catch (\Throwable $x) { throw Db::isDuplicate($x) ? new HttpError(409, 'This learner is already enrolled in the section.') : $x; }
        Audit::log(Auth::user()['id'], 'enrollment.created', 'enrollment', $id, ['learner_id' => $lid, 'course_id' => (int)$sec['course_id']]);
        Audit::notify($lid, 'enrolled', ['course_id' => (int)$sec['course_id']]);
        Response::json(201, ['id' => $id]);
    }

    public static function modules(Request $r): void
    {
        $u = Auth::user(); $cid = $r->id(); Access::needCourse($cid); Access::needView($u, $cid);
        $mgr = Access::manages($u, $cid);
        $vis = $mgr ? '' : ' AND published_at IS NOT NULL AND published_at <= now()';
        $mods = Db::all("SELECT id, title, position, published_at FROM modules WHERE course_id = ?$vis ORDER BY position, id", [$cid]);
        foreach ($mods as &$m) {
            $m['lessons'] = Db::all("SELECT l.id, l.title, l.position, l.published_at, (lp.lesson_id IS NOT NULL) AS completed
                FROM lessons l LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.learner_id = ?
                WHERE l.module_id = ?" . ($mgr ? '' : ' AND l.published_at IS NOT NULL AND l.published_at <= now()') . ' ORDER BY l.position, l.id', [$u['id'], $m['id']]);
        }
        Response::json(200, ['data' => $mods]);
    }

    public static function createModule(Request $r): void
    {
        $cid = $r->id(); Access::needCourse($cid); Access::needManage(Auth::user(), $cid);
        $id = (int)Db::one('INSERT INTO modules(course_id, title, position, published_at) VALUES (?, ?, ?, CASE WHEN ?::boolean THEN now() END) RETURNING id',
            [$cid, V::str($r->body, 'title', 2, 200), V::int($r->body, 'position', false) ?? 0, V::bool($r->body, 'publish')])['id'];
        Audit::log(Auth::user()['id'], 'module.created', 'module', $id, ['course_id' => $cid]);
        Response::json(201, ['id' => $id]);
    }

    private static function lessonCourse(int $lessonId): array
    {
        return Db::one('SELECT l.id, l.title, l.body, l.published_at, m.course_id FROM lessons l JOIN modules m ON m.id = l.module_id WHERE l.id = ?', [$lessonId]) ?? throw new HttpError(404, 'Lesson not found.');
    }

    public static function createLesson(Request $r): void
    {
        $m = Db::one('SELECT id, course_id FROM modules WHERE id = ?', [$r->id()]) ?? throw new HttpError(404, 'Module not found.');
        Access::needManage(Auth::user(), (int)$m['course_id']);
        $id = (int)Db::one('INSERT INTO lessons(module_id, title, body, position, published_at) VALUES (?, ?, ?, ?, CASE WHEN ?::boolean THEN now() END) RETURNING id',
            [$m['id'], V::str($r->body, 'title', 2, 200), V::str($r->body, 'body', 0, 100000, false) ?? '', V::int($r->body, 'position', false) ?? 0, V::bool($r->body, 'publish')])['id'];
        Audit::log(Auth::user()['id'], 'lesson.created', 'lesson', $id, ['course_id' => (int)$m['course_id']]);
        Response::json(201, ['id' => $id]);
    }

    public static function lesson(Request $r): void
    {
        $u = Auth::user(); $l = self::lessonCourse($r->id()); $cid = (int)$l['course_id'];
        Access::needView($u, $cid);
        if (!Access::manages($u, $cid) && (!$l['published_at'] || strtotime($l['published_at']) > time())) throw new HttpError(404, 'Lesson not found.');
        Response::json(200, $l);
    }

    public static function completeLesson(Request $r): void
    {
        $u = Auth::user(); $l = self::lessonCourse($r->id()); $cid = (int)$l['course_id'];
        if (!Access::enrolled($u, $cid)) throw new HttpError(403, 'Only enrolled learners record progress.');
        if (!$l['published_at'] || strtotime($l['published_at']) > time()) throw new HttpError(404, 'Lesson not found.');
        Db::run('INSERT INTO lesson_progress(learner_id, lesson_id) VALUES (?, ?) ON CONFLICT DO NOTHING', [$u['id'], $l['id']]);
        Response::json(200, ['lesson_id' => (int)$l['id'], 'completed' => true]);
    }

    public static function progress(Request $r): void
    {
        $u = Auth::user(); $cid = $r->id(); Access::needCourse($cid);
        if (!Access::enrolled($u, $cid)) throw new HttpError(403, 'Only enrolled learners have progress.');
        $row = Db::one("SELECT count(l.id) AS total, count(lp.lesson_id) AS done FROM lessons l JOIN modules m ON m.id = l.module_id
            LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.learner_id = ?
            WHERE m.course_id = ? AND l.published_at IS NOT NULL AND l.published_at <= now() AND m.published_at IS NOT NULL AND m.published_at <= now()", [$u['id'], $cid]);
        $t = (int)$row['total']; $d = (int)$row['done'];
        Response::json(200, ['completed' => $d, 'total' => $t, 'percent' => $t ? (int)round(100 * $d / $t) : 0]);
    }

    public static function announcements(Request $r): void
    {
        $cid = $r->id(); Access::needCourse($cid); Access::needView(Auth::user(), $cid);
        Response::json(200, ['data' => Db::all('SELECT a.id, a.title, a.body, a.published_at, u.full_name AS author FROM announcements a JOIN users u ON u.id = a.author_id WHERE a.course_id = ? ORDER BY a.published_at DESC LIMIT 50', [$cid])]);
    }

    public static function createAnnouncement(Request $r): void
    {
        $u = Auth::user(); $cid = $r->id(); Access::needCourse($cid); Access::needManage($u, $cid);
        $title = V::str($r->body, 'title', 2, 200); $body = V::str($r->body, 'body', 1, 10000);
        $id = Db::tx(function () use ($u, $cid, $title, $body) {
            $id = (int)Db::one('INSERT INTO announcements(course_id, author_id, title, body) VALUES (?, ?, ?, ?) RETURNING id', [$cid, $u['id'], $title, $body])['id'];
            Db::run("INSERT INTO notifications(user_id, type, payload) SELECT e.learner_id, 'announcement', ?::jsonb FROM enrollments e
                JOIN course_sections s ON s.id = e.section_id WHERE s.course_id = ? AND e.status = 'active'", [json_encode(['course_id' => $cid, 'announcement_id' => $id, 'title' => $title]), $cid]);
            Audit::log((int)$u['id'], 'announcement.created', 'announcement', $id, ['course_id' => $cid]);
            return $id;
        });
        Response::json(201, ['id' => $id]);
    }
}
