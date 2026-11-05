<?php
declare(strict_types=1);
namespace App;

/** Object-level authorization. Role checks alone are never enough. */
final class Access
{
    public static function isAdmin(array $u): bool { return in_array('administrator', $u['roles'], true); }

    public static function manages(array $u, int $courseId): bool
    {
        return self::isAdmin($u) || (bool)Db::one('SELECT 1 FROM course_sections WHERE course_id = ? AND instructor_id = ?', [$courseId, $u['id']]);
    }

    public static function enrolled(array $u, int $courseId): bool
    {
        return (bool)Db::one("SELECT 1 FROM enrollments e JOIN course_sections s ON s.id = e.section_id WHERE s.course_id = ? AND e.learner_id = ? AND e.status = 'active'", [$courseId, $u['id']]);
    }

    public static function needManage(array $u, int $courseId): void
    {
        if (!self::manages($u, $courseId)) throw new HttpError(403, 'You do not manage this course.');
    }

    public static function needView(array $u, int $courseId): void
    {
        if (!self::manages($u, $courseId) && !self::enrolled($u, $courseId)) throw new HttpError(403, 'You are not part of this course.');
    }

    public static function needCourse(int $id): array
    {
        return Db::one('SELECT * FROM courses WHERE id = ?', [$id]) ?? throw new HttpError(404, 'Course not found.');
    }
}
