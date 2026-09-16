<?php

declare(strict_types=1);

namespace OcMaker;

final class PdfFilename
{
    public static function build(
        string $campanha,
        string $anunciante = '',
        ?\DateTimeInterface $when = null,
    ): string {
        $when ??= new \DateTimeImmutable('now');
        $camp = self::sanitizePart($campanha, 'campanha');
        $adv = self::sanitizePart($anunciante, 'anunciante');
        $stamp = $when->format('Ymd_His');
        return "{$camp} - {$adv} - {$stamp}.pdf";
    }

    private static function sanitizePart(string $text, string $default): string
    {
        $name = trim($text) !== '' ? trim($text) : $default;
        $name = self::removeAccents($name);
        return preg_replace('/[\\\\\\/:*?"<>|]/u', '_', $name) ?? $name;
    }

    private static function removeAccents(string $text): string
    {
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($transliterator !== null) {
                return $transliterator->transliterate($text);
            }
        }
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($normalized)) {
                $stripped = preg_replace('/\p{Mn}/u', '', $normalized);
                if (is_string($stripped)) {
                    return $stripped;
                }
            }
        }
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return $converted !== false ? $converted : $text;
    }
}
