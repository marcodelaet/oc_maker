<?php

declare(strict_types=1);

namespace OcMaker;

final class SessionAuth
{
    private const SESSION_KEY = 'oc_maker_user_id';

    /** @var array<string, mixed>|null */
    private static ?array $cachedUser = null;

    private static bool $userResolved = false;

    /** @param array<string, mixed> $user */
    public static function login(array $user): void
    {
        startSecureSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        self::clearUserCache();
    }

    public static function logout(): void
    {
        startSecureSession();
        unset($_SESSION[self::SESSION_KEY], $_SESSION['csrf_token']);
        session_regenerate_id(true);
        self::clearUserCache();
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        if (self::$userResolved) {
            return self::$cachedUser;
        }

        self::$userResolved = true;
        startSecureSession();
        $id = (int) ($_SESSION[self::SESSION_KEY] ?? 0);
        if ($id <= 0) {
            self::$cachedUser = null;

            return null;
        }

        $repo = new UserRepository();
        $user = $repo->findById($id);
        if ($user === null || !(bool) ($user['active'] ?? false)) {
            self::logout();

            return null;
        }

        self::$cachedUser = $user;

        return $user;
    }

    /** @param array<string, mixed> $user */
    public static function refreshUser(array $user): void
    {
        startSecureSession();
        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        self::$cachedUser = $user;
        self::$userResolved = true;
    }

    private static function clearUserCache(): void
    {
        self::$cachedUser = null;
        self::$userResolved = false;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user ? (int) $user['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            jsonResponse(['error' => 'Autenticação necessária.'], 401);
        }

        return $user;
    }

    public static function requireRole(string $permission): array
    {
        $user = self::requireLogin();
        $role = (string) ($user['role'] ?? '');
        if (!Permission::roleCan($role, $permission) && !Permission::roleCan($role, '*')) {
            jsonResponse(['error' => 'Permissão negada.'], 403);
        }

        return $user;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && ($user['role'] ?? '') === 'administrador';
    }
}
