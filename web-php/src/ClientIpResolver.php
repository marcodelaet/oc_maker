<?php

declare(strict_types=1);

namespace OcMaker;

final class ClientIpResolver
{
    /** @var list<string> */
    private const PROXY_HOPS = [
        '127.0.0.1',
        '::1',
        '172.17.0.1',
        '172.18.0.1',
        '172.19.0.1',
        '192.168.65.1',
        '192.168.65.254',
    ];

    public static function resolve(?string $clientReportedIp = null): ?string
    {
        $candidates = self::collectCandidates();
        $reported = self::sanitize($clientReportedIp);

        foreach ($candidates as $ip) {
            if (!self::isKnownProxyHop($ip)) {
                return $ip;
            }
        }

        if ($reported !== null && self::isPlausibleClientIp($reported)) {
            return $reported;
        }

        foreach ($candidates as $ip) {
            return $ip;
        }

        return $reported;
    }

    /** @return list<string> */
    private static function collectCandidates(): array
    {
        $out = [];
        $sources = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($sources as $key) {
            $value = $_SERVER[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            if ($key === 'HTTP_X_FORWARDED_FOR') {
                foreach (explode(',', $value) as $part) {
                    $ip = self::sanitize(trim($part));
                    if ($ip !== null) {
                        $out[] = $ip;
                    }
                }
                continue;
            }

            $ip = self::sanitize(trim($value));
            if ($ip !== null) {
                $out[] = $ip;
            }
        }

        return $out;
    }

    private static function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '[') && str_contains($value, ']')) {
            $value = substr($value, 1, strpos($value, ']') - 1);
        }

        if (!filter_var($value, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $value;
    }

    private static function isKnownProxyHop(string $ip): bool
    {
        if (in_array($ip, self::PROXY_HOPS, true)) {
            return true;
        }

        return (bool) preg_match('/^172\.(1[6-9]|2\d|3[01])\.0\.1$/', $ip);
    }

    private static function isPlausibleClientIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (in_array($ip, ['0.0.0.0', '255.255.255.255'], true)) {
            return false;
        }

        return !self::isKnownProxyHop($ip);
    }
}
