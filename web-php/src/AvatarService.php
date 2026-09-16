<?php

declare(strict_types=1);

namespace OcMaker;

final class AvatarService
{
    private const MAX_BYTES = 2_097_152;

    /** @var list<string> */
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function storageDir(): string
    {
        $dir = dirname(__DIR__) . '/storage/avatars';
        ensureStorageDirectory($dir, 'avatars');

        return $dir;
    }

    public static function urlForUser(int $userId, ?string $avatarPath): ?string
    {
        if ($avatarPath === null || $avatarPath === '') {
            return null;
        }
        $full = self::storageDir() . '/' . basename($avatarPath);
        if (!is_file($full)) {
            return null;
        }

        $version = (string) filemtime($full);

        return url('avatar.php?id=' . $userId . '&v=' . $version);
    }

    /** @param array<string, mixed> $user */
    public static function publicAvatarUrl(array $user): ?string
    {
        return self::urlForUser((int) $user['id'], isset($user['avatar_path']) ? (string) $user['avatar_path'] : null);
    }

    public function save(int $userId, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Falha no upload da imagem.');
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('A imagem deve ter no máximo 2 MB.');
        }

        $mime = $this->detectMime((string) ($file['tmp_name'] ?? ''));
        if ($mime === null) {
            throw new \RuntimeException('Use JPG, PNG ou WebP.');
        }

        $ext = self::ALLOWED_MIME[$mime];
        $filename = $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = self::storageDir() . '/' . $filename;

        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            throw new \RuntimeException('Não foi possível salvar a imagem.');
        }

        return $filename;
    }

    public function deleteFile(?string $avatarPath): void
    {
        if ($avatarPath === null || $avatarPath === '') {
            return;
        }
        $full = self::storageDir() . '/' . basename($avatarPath);
        if (is_file($full)) {
            unlink($full);
        }
    }

    private function detectMime(string $tmpPath): ?string
    {
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        return is_string($mime) && isset(self::ALLOWED_MIME[$mime]) ? $mime : null;
    }
}
