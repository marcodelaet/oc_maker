<?php

declare(strict_types=1);

namespace OcMaker;

final class PageShell
{
    public static function userInitials(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: []));
        if ($parts === []) {
            return '?';
        }
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    }

    public static function topBar(string $backHref, string $backLabel = 'Voltar ao OC Maker'): string
    {
        $home = htmlspecialchars(url('index.php'), ENT_QUOTES, 'UTF-8');

        return '<header class="page-topbar">'
            . self::brandLink($home)
            . IconHelper::backIconLink($backHref, $backLabel)
            . '</header>';
    }

    public static function brandLink(string $href): string
    {
        $logo = htmlspecialchars(url('assets/logo_retail_media.png'), ENT_QUOTES, 'UTF-8');
        $logoFallback = htmlspecialchars(url('assets/logo_converta.svg'), ENT_QUOTES, 'UTF-8');
        $safeHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');

        return '<a href="' . $safeHref . '" class="page-topbar-brand">'
            . '<img src="' . $logo . '" alt="Retail Media" class="brand-logo-img" onerror="this.onerror=null;this.src=\'' . $logoFallback . '\'">'
            . '<span class="brand-title">OC Maker</span>'
            . '</a>';
    }

    public static function defaultAvatarHtml(string $sizeClass = 'avatar-md', bool $withRing = false): string
    {
        $ring = $withRing ? ' avatar-ring' : '';

        return '<span class="user-avatar user-avatar--default ' . $sizeClass . $ring . '" aria-hidden="true">'
            . IconHelper::svg('user', self::avatarIconSize($sizeClass))
            . '</span>';
    }

    /** Avatar clicável com overlay para troca de foto. */
    public static function avatarPickerHtml(?array $user, string $sizeClass = 'avatar-xl', string $idPrefix = ''): string
    {
        $pickerId = $idPrefix !== '' ? $idPrefix . 'AvatarPicker' : 'avatarPicker';
        $mediaId = $idPrefix !== '' ? $idPrefix . 'HeroAvatar' : 'heroAvatar';
        $media = self::avatarHtml($user, $sizeClass, true);
        $camera = IconHelper::svg('camera', self::avatarIconSize($sizeClass) > 28 ? 24 : 22);

        return '<button type="button" class="avatar-picker ' . $sizeClass . '" id="' . $pickerId . '" aria-label="Mudar foto de perfil">'
            . '<span class="avatar-picker-media" id="' . $mediaId . '">' . $media . '</span>'
            . '<span class="avatar-picker-overlay" aria-hidden="true">'
            . $camera
            . '<span class="avatar-picker-label">Mudar foto de perfil</span>'
            . '</span>'
            . '</button>';
    }

    /** @param array<string, mixed>|null $user */
    public static function avatarHtml(?array $user, string $sizeClass = 'avatar-md', bool $withRing = false): string
    {
        $name = is_array($user) ? (string) ($user['name'] ?? '') : '';
        $ring = $withRing ? ' avatar-ring' : '';
        $avatarUrl = is_array($user) ? ($user['avatar_url'] ?? null) : null;
        $hasPhoto = is_string($avatarUrl) && $avatarUrl !== '';

        if (!$hasPhoto) {
            return self::defaultAvatarHtml($sizeClass, $withRing);
        }

        $safeUrl = htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8');

        return '<span class="user-avatar-img ' . $sizeClass . $ring . '" aria-hidden="true">'
            . '<img src="' . $safeUrl . '" alt="">'
            . '</span>';
    }

    private static function avatarIconSize(string $sizeClass): int
    {
        if (str_contains($sizeClass, 'avatar-xs')) {
            return 16;
        }
        if (str_contains($sizeClass, 'avatar-sm')) {
            return 18;
        }
        if (str_contains($sizeClass, 'avatar-lg')) {
            return 28;
        }
        if (str_contains($sizeClass, 'avatar-xl')) {
            return 32;
        }

        return 22;
    }

    public static function statusBadge(bool $active, string $activeLabel, string $inactiveLabel): string
    {
        $class = $active ? 'status-badge status-badge--active' : 'status-badge status-badge--inactive';
        $label = $active ? $activeLabel : $inactiveLabel;

        return '<span class="' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
