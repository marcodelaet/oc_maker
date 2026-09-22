<?php

declare(strict_types=1);

namespace OcMaker;

final class AdmoohDeviceMatcher
{
    public static function cleanDeviceName(string $deviceName): string
    {
        $name = trim($deviceName);
        $name = preg_replace('/\s*\|\s*Face\d+\s*$/iu', '', $name) ?? $name;
        $name = preg_replace('/_App$/iu', '', $name) ?? $name;

        return trim($name);
    }

    /** @param list<string> $knownScreenCodes */
    public static function match(string $deviceName, array $knownScreenCodes): ?string
    {
        $cleaned = self::cleanDeviceName($deviceName);
        if ($cleaned === '') {
            return null;
        }

        if ($knownScreenCodes === []) {
            return $cleaned;
        }

        $normCleaned = self::normalizeKey($cleaned);

        foreach ($knownScreenCodes as $code) {
            if (strcasecmp($code, $cleaned) === 0) {
                return $code;
            }
        }

        foreach ($knownScreenCodes as $code) {
            if (self::normalizeKey($code) === $normCleaned) {
                return $code;
            }
        }

        $tokenCleaned = self::normalizeFaceTokens($cleaned);
        foreach ($knownScreenCodes as $code) {
            if (self::normalizeKey(self::normalizeFaceTokens($code)) === self::normalizeKey($tokenCleaned)) {
                return $code;
            }
        }

        $prefixCleaned = self::extractUnitFacePrefix($cleaned);
        $best = null;
        $bestScore = -1;
        foreach ($knownScreenCodes as $code) {
            if ($prefixCleaned === '' || self::extractUnitFacePrefix($code) !== $prefixCleaned) {
                continue;
            }

            similar_text(self::normalizeKey($cleaned), self::normalizeKey($code), $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $code;
            }
        }
        if ($best !== null && $bestScore >= 55) {
            return $best;
        }

        foreach ($knownScreenCodes as $code) {
            $normCode = self::normalizeKey($code);
            if ($normCode === '' || $normCleaned === '') {
                continue;
            }
            if (str_contains($normCleaned, $normCode) || str_contains($normCode, $normCleaned)) {
                return $code;
            }
        }

        return null;
    }

    public static function normalizeKey(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }

        return preg_replace('/[^A-Z0-9]/', '', $value) ?? $value;
    }

    private static function normalizeFaceTokens(string $code): string
    {
        return (string) (preg_replace_callback(
            '/-T(\d+)(?=-|$)/iu',
            static fn(array $matches): string => '-T' . str_pad($matches[1], 2, '0', STR_PAD_LEFT),
            $code,
        ) ?? $code);
    }

    private static function extractUnitFacePrefix(string $code): string
    {
        if (preg_match('/^(\d+-[A-Z]+-T\d+)/iu', $code, $matches)) {
            return mb_strtoupper($matches[1], 'UTF-8');
        }

        return '';
    }
}
