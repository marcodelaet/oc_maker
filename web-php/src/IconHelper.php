<?php

declare(strict_types=1);

namespace OcMaker;

final class IconHelper
{
    /** @var array<string, string> */
    private const PATHS = [
        'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'camera' => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    ];

    public static function svg(string $name, int $size = 20): string
    {
        $body = self::PATHS[$name] ?? '';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            $size,
            $size,
            $body
        );
    }

    public static function link(string $href, string $name, string $label, string $extraClass = ''): string
    {
        $cls = $extraClass !== '' ? 'icon-btn ' . $extraClass : 'icon-btn';
        $safeHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        return sprintf(
            '<a href="%s" class="%s" title="%s" aria-label="%s">%s</a>',
            $safeHref,
            htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'),
            $safeLabel,
            $safeLabel,
            self::svg($name)
        );
    }

    public static function backIconLink(string $href, string $label = 'Voltar ao OC Maker'): string
    {
        return self::link($href, 'arrow-left', $label, 'icon-btn--muted');
    }

    public static function backNav(string $href, string $label = 'Voltar ao OC Maker'): string
    {
        return '<nav class="page-back" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
            . self::backIconLink($href, $label)
            . '</nav>';
    }
}
