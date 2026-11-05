<?php
declare(strict_types=1);
namespace App;

/** Input validation. Every failure is a 422 naming the field. */
final class V
{
    private static function fail(string $k, string $msg): never { throw new HttpError(422, 'Please fix the highlighted fields.', [$k => $msg]); }

    public static function str(array $b, string $k, int $min = 1, int $max = 255, bool $req = true): ?string
    {
        $v = $b[$k] ?? null;
        if ($v === null || $v === '') { if ($req) self::fail($k, 'This field is required.'); return null; }
        if (!is_string($v)) self::fail($k, 'Must be text.');
        $v = trim($v);
        $n = mb_strlen($v);
        if ($n < $min || $n > $max) self::fail($k, "Must be $min to $max characters.");
        return $v;
    }

    public static function int(array $b, string $k, bool $req = true): ?int
    {
        $v = $b[$k] ?? null;
        if ($v === null || $v === '') { if ($req) self::fail($k, 'This field is required.'); return null; }
        if (filter_var($v, FILTER_VALIDATE_INT) === false) self::fail($k, 'Must be a whole number.');
        return (int)$v;
    }

    public static function num(array $b, string $k): float
    {
        $v = $b[$k] ?? null;
        if ($v === null || !is_numeric($v)) self::fail($k, 'Must be a number.');
        return (float)$v;
    }

    public static function bool(array $b, string $k, bool $default = false): bool
    {
        return array_key_exists($k, $b) ? filter_var($b[$k], FILTER_VALIDATE_BOOLEAN) : $default;
    }

    public static function ts(array $b, string $k): string
    {
        $v = self::str($b, $k, 8, 40);
        try { return (new \DateTimeImmutable($v))->format(DATE_ATOM); }
        catch (\Exception) { self::fail($k, 'Use a date and time like 2026-12-01T17:00:00+08:00.'); }
    }

    public static function email(array $b, string $k): string
    {
        $v = strtolower(self::str($b, $k, 3, 254));
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) self::fail($k, 'Enter a valid email address.');
        return $v;
    }

    public static function page(array $q): array
    {
        $per = max(1, min(100, (int)($q['per_page'] ?? 25)));
        $page = max(1, (int)($q['page'] ?? 1));
        return [$per, ($page - 1) * $per, $page];
    }
}
