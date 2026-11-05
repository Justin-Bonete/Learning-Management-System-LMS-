<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Access, Audit, Auth, Db, Files, HttpError, Request, Response, V};

final class AssignmentController
{
    public static function create(Request $r): void
    {
        $u = Auth::user(); $cid = $r->id(); Access::needCourse($cid); Access::needManage($u, $cid);
        $b = $r->body;
        $max = V::num($b, 'max_points');
        if ($max <= 0 || $max > 10000) throw new HttpError(422, 'Please fix the highlighted fields.', ['max_points' => 'Must be greater than 0.']);
        $id = (int)Db::one('INSERT INTO assignments(course_id, title, instructions, due_at, max_points, allow_late, published_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ?::boolean THEN now() END, ?) RETURNING id',
            [$cid, V::str($b, 'title', 2, 200), V::str($b, 'instructions', 0, 20000, false) ?? '', V::ts($b, 'due_at'), $max, V::bool($b, 'allow_late', true), V::bool($b, 'publish'), $u['id']])['id'];
        Audit::log((int)$u['id'], 'assignment.created', 'assignment', $id, ['course_id' => $cid]);
        Response::json(201, ['id' => $id]);
    }

    public static function index(Request $r): void
    {
        $u = Auth::user(); $cid = $r->id(); Access::needCourse($cid); Access::needView($u, $cid);
        $mgr = Access::manages($u, $cid);
        $rows = Db::all('SELECT a.id, a.title, a.instructions, a.due_at, a.max_points, a.allow_late, a.published_at, s.id AS submission_id, s.submitted_at, s.is_late, g.points, g.feedback
            FROM assignments a LEFT JOIN submissions s ON s.assignment_id = a.id AND s.learner_id = ? LEFT JOIN grades g ON g.submission_id = s.id
            WHERE a.course_id = ?' . ($mgr ? '' : ' AND a.published_at IS NOT NULL AND a.published_at <= now()') . ' ORDER BY a.due_at', [$u['id'], $cid]);
        Response::json(200, ['data' => $rows]);
    }

    private static function assignment(int $id): array
    {
        return Db::one('SELECT id, course_id, title, due_at, max_points, allow_late, published_at, (due_at < now()) AS past FROM assignments WHERE id = ?', [$id]) ?? throw new HttpError(404, 'Assignment not found.');
    }

    public static function submit(Request $r): void
    {
        $u = Auth::user(); $a = self::assignment($r->id()); $cid = (int)$a['course_id'];
        if (!Access::enrolled($u, $cid)) throw new HttpError(403, 'Only enrolled learners can submit.');
        if (!$a['published_at'] || strtotime($a['published_at']) > time()) throw new HttpError(404, 'Assignment not found.');
        $late = (bool)$a['past'];   // decided by the server clock, not the browser
        if ($late && !$a['allow_late']) throw new HttpError(422, 'The deadline has passed and late work is not accepted.');
        $text = V::str($r->body, 'body', 0, 50000, false);
        $file = isset($_FILES['file']) ? Files::store($_FILES['file']) : null;
        if ($text === null && !$file) throw new HttpError(422, 'Please fix the highlighted fields.', ['body' => 'Add a response or attach a file.']);
        try {
            $id = (int)Db::one('INSERT INTO submissions(assignment_id, learner_id, body, storage_key, filename, is_late) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                [$a['id'], $u['id'], $text, $file[0] ?? null, $file[1] ?? null, $late])['id'];
        } catch (\Throwable $x) { throw Db::isDuplicate($x) ? new HttpError(409, 'You already submitted this assignment.') : $x; }
        Audit::log((int)$u['id'], 'submission.created', 'submission', $id, ['assignment_id' => (int)$a['id'], 'late' => $late]);
        Response::json(201, ['id' => $id, 'is_late' => $late]);
    }

    public static function submissions(Request $r): void
    {
        $a = self::assignment($r->id()); Access::needManage(Auth::user(), (int)$a['course_id']);
        Response::json(200, ['data' => Db::all('SELECT s.id, u.full_name AS learner, s.submitted_at, s.is_late, s.status, s.body, s.filename, g.points, g.feedback
            FROM submissions s JOIN users u ON u.id = s.learner_id LEFT JOIN grades g ON g.submission_id = s.id WHERE s.assignment_id = ? ORDER BY s.submitted_at', [$a['id']])]);
    }

    public static function grade(Request $r): void
    {
        $u = Auth::user();
        $s = Db::one('SELECT s.id, s.learner_id, a.course_id, a.max_points, a.title FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE s.id = ?', [$r->id()]) ?? throw new HttpError(404, 'Submission not found.');
        Access::needManage($u, (int)$s['course_id']);
        $pts = V::num($r->body, 'points');
        if ($pts < 0 || $pts > (float)$s['max_points']) throw new HttpError(422, 'Please fix the highlighted fields.', ['points' => 'Enter a score from 0 to ' . $s['max_points'] . '.']);
        $fb = V::str($r->body, 'feedback', 0, 4000, false);
        Db::tx(function () use ($u, $s, $pts, $fb) {
            $old = Db::one('SELECT points FROM grades WHERE submission_id = ? FOR UPDATE', [$s['id']]);
            Db::run('INSERT INTO grades(submission_id, grader_id, points, feedback) VALUES (?, ?, ?, ?)
                ON CONFLICT (submission_id) DO UPDATE SET grader_id = EXCLUDED.grader_id, points = EXCLUDED.points, feedback = EXCLUDED.feedback, graded_at = now()', [$s['id'], $u['id'], $pts, $fb]);
            Db::run("UPDATE submissions SET status = 'graded' WHERE id = ?", [$s['id']]);
            Audit::log((int)$u['id'], $old ? 'grade.changed' : 'grade.created', 'submission', (int)$s['id'], ['old' => $old['points'] ?? null, 'new' => $pts, 'learner_id' => (int)$s['learner_id']]);
            Audit::notify((int)$s['learner_id'], 'graded', ['submission_id' => (int)$s['id'], 'assignment' => $s['title']]);
        });
        Response::json(200, ['submission_id' => (int)$s['id'], 'points' => $pts]);
    }

    public static function download(Request $r): void
    {
        $u = Auth::user();
        $s = Db::one('SELECT s.learner_id, s.storage_key, s.filename, a.course_id FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE s.id = ?', [$r->id()]);
        if (!$s || !$s['storage_key']) throw new HttpError(404, 'File not found.');
        if ((int)$s['learner_id'] !== (int)$u['id']) Access::needManage($u, (int)$s['course_id']);
        $path = Files::path($s['storage_key']);
        if (!is_file($path)) throw new HttpError(404, 'File not found.');
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($s['filename']));
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
