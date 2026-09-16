<?php

declare(strict_types=1);

namespace OcMaker;

final class Permission
{
    public const ROLES = [
        'administrador',
        'business_intelligence',
        'checking',
        'financeiro',
        'comercial',
    ];

    /** @return list<string> */
    public static function permissionsForRole(string $role): array
    {
        return match ($role) {
            'administrador' => ['*'],
            'financeiro' => ['pdf', 'history', 'calculator', 'inventory'],
            'business_intelligence' => ['pdf', 'history', 'inventory', 'reports'],
            'checking' => ['pdf', 'history', 'inventory'],
            'comercial' => ['pdf', 'history'],
            default => ['pdf', 'history'],
        };
    }

    public static function roleCan(string $role, string $permission): bool
    {
        $perms = self::permissionsForRole($role);
        if (in_array('*', $perms, true)) {
            return true;
        }

        return in_array($permission, $perms, true);
    }

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Perfil de usuário inválido.');
        }

        return $role;
    }
}
