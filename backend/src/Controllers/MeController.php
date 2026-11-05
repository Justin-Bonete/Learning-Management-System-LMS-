<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Auth, Db, HttpError, Request, Response};

final class MeController
{
    public static function grades(Request $r): void
    {
        Response::json(200, ['data' => Db::all('SELECT a.id AS assignment_id, a.title, c.code, a.max_points, s.submitted_at, s.is_late, g.points, g.feedback, g.graded_at
            FROM submissions s JOIN assignments a ON a.id = s.assignment_id JOIN courses c ON c.id = a.course_id LEFT JOIN grades g ON g.submission_id = s.id
            WHERE s.learner_id = ? ORDER BY s.submitted_at DESC', [Auth::user()['id']])]);
    }

    public static function notifications(Request $r): void
    {
        $rows = Db::all('SELECT id, type, payload, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50', [Auth::user()['id']]);
        foreach ($rows as &$n) $n['payload'] = json_decode($n['payload'], true);
        Response::json(200, ['data' => $rows]);
    }

    public static function markRead(Request $r): void
    {
        if (!Db::run('UPDATE notifications SET read_at = now() WHERE id = ? AND user_id = ? AND read_at IS NULL', [$r->id(), Auth::user()['id']])) throw new HttpError(404, 'Notification not found.');
        Response::json(200, ['id' => $r->id(), 'read' => true]);
    }
}
