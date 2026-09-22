<?php

declare(strict_types=1);

namespace OcMaker;

final class DocumentAccessService
{
    /** @param array<string, mixed>|null $user */
    public static function isAdmin(?array $user): bool
    {
        return $user !== null && ($user['role'] ?? '') === 'administrador';
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $document */
    public static function canAccessHomeDocument(array $user, array $document): bool
    {
        if (self::isAdmin($user)) {
            return true;
        }

        $ownerId = isset($document['created_by']) ? (int) $document['created_by'] : 0;
        if ($ownerId <= 0) {
            return false;
        }

        return $ownerId === (int) ($user['id'] ?? 0);
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $document */
    public static function assertHomeDocumentAccess(array $user, array $document): void
    {
        if (!self::canAccessHomeDocument($user, $document)) {
            jsonResponse(['error' => 'Acesso negado a este documento.'], 403);
        }
    }

    /** @return array<string, mixed> */
    public static function requireLogin(): array
    {
        return SessionAuth::requireLogin();
    }
}
