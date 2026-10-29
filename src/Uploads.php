<?php

declare(strict_types=1);

namespace WynForms;

/**
 * File attachments. Stored outside the web root under random names with no extension,
 * so an uploaded script can never be executed. Only signed-in users can download them.
 */
final class Uploads
{
    public const MAX_FILES = 5;

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * Normalise PHP's $_FILES into a flat list.
     *
     * @return list<array{field: string, name: string, tmp: string, size: int, error: int}>
     */
    public static function fromGlobals(array $files): array
    {
        $out = [];
        foreach ($files as $field => $f) {
            if (is_array($f['name'])) {
                foreach (array_keys($f['name']) as $i) {
                    $out[] = ['field' => (string) $field, 'name' => (string) $f['name'][$i], 'tmp' => (string) $f['tmp_name'][$i], 'size' => (int) $f['size'][$i], 'error' => (int) $f['error'][$i]];
                }
            } else {
                $out[] = ['field' => (string) $field, 'name' => (string) $f['name'], 'tmp' => (string) $f['tmp_name'], 'size' => (int) $f['size'], 'error' => (int) $f['error']];
            }
        }

        return array_values(array_filter($out, fn ($f) => $f['error'] !== UPLOAD_ERR_NO_FILE));
    }

    /**
     * Check every file before anything is stored.
     *
     * @param list<array> $files
     */
    public static function validate(array $files, int $maxMb): ?string
    {
        if (count($files) > self::MAX_FILES) {
            return 'Attach at most ' . self::MAX_FILES . ' files.';
        }
        foreach ($files as $f) {
            if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > $maxMb * 1024 * 1024) {
                return "“{$f['name']}” is larger than {$maxMb} MB.";
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                return "“{$f['name']}” didn’t upload completely. Try again.";
            }
        }

        return null;
    }

    /** @return array{stored_name: string, size: int, mime: string, original_name: string} */
    public function store(array $file, bool $fromHttpUpload = true): array
    {
        $sub = date('Y/m');
        $dir = $this->dir . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create upload folder: {$dir}");
        }

        $stored = $sub . '/' . bin2hex(random_bytes(16));
        $target = $this->dir . '/' . $stored;
        $ok = $fromHttpUpload ? move_uploaded_file($file['tmp'], $target) : copy($file['tmp'], $target);
        if (!$ok) {
            throw new \RuntimeException('Could not save the uploaded file.');
        }

        $mime = 'application/octet-stream';
        if (function_exists('finfo_open') && ($finfo = finfo_open(FILEINFO_MIME_TYPE))) {
            $mime = finfo_file($finfo, $target) ?: $mime;
        }

        return [
            'stored_name'   => $stored,
            'size'          => (int) filesize($target),
            'mime'          => $mime,
            'original_name' => self::cleanName($file['name']),
        ];
    }

    public function path(string $stored): ?string
    {
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}$#', $stored)) {
            return null;
        }
        $path = $this->dir . '/' . $stored;

        return is_file($path) ? $path : null;
    }

    public function remove(string $stored): void
    {
        if ($path = $this->path($stored)) {
            @unlink($path);
        }
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name) ?? 'file';

        return mb_substr(trim($name, ' .') ?: 'file', 0, 150);
    }
}
