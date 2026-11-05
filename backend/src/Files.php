<?php
declare(strict_types=1);
namespace App;

/** Uploads live outside the web root and are served only through authorized endpoints. */
final class Files
{
    private const MAX = 5 * 1024 * 1024;
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private static function dir(): string
    {
        $d = (getenv('STORAGE_PATH') ?: '/var/www/storage') . '/uploads';
        if (!is_dir($d)) mkdir($d, 0750, true);
        return $d;
    }

    public static function path(string $key): string { return self::dir() . '/' . basename($key); }

    /** @return array{0:string,1:string,2:string,3:int} key, original name, mime, size */
    public static function store(array $f): array
    {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new HttpError(422, 'The file could not be uploaded.', ['file' => 'Upload failed. Try again.']);
        if ($f['size'] > self::MAX) throw new HttpError(422, 'File is too large.', ['file' => 'Maximum size is 5 MB.']);
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf' => ['application/pdf'], 'docx' => [self::DOCX, 'application/zip'], 'zip' => ['application/zip'],
                    'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg']];
        if (!isset($allowed[$ext])) throw new HttpError(422, 'File type not allowed.', ['file' => 'Use PDF, DOCX, ZIP, PNG, or JPG.']);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($mime, $allowed[$ext], true)) throw new HttpError(422, 'File content does not match its extension.', ['file' => 'The file looks different from its extension.']);
        $key = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], self::dir() . '/' . $key)) throw new HttpError(500, 'Could not store the file.');
        return [$key, preg_replace('/[^\w.\- ]/u', '_', basename($f['name'])), $mime, (int)$f['size']];
    }
}
