<?php

declare(strict_types=1);

namespace OcMaker;

final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return false;
        }
        $timeSlice = (int) floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeForSlice($secret, $timeSlice + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function provisioningUri(string $secret, string $email, string $issuer = 'OC Maker Retail Media'): string
    {
        $label = rawurlencode($issuer . ':' . $email);
        $issuerEnc = rawurlencode($issuer);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuerEnc}&algorithm=SHA1&digits=6&period=30";
    }

    private static function codeForSlice(string $secret, int $timeSlice): string
    {
        $key = self::base32Decode($secret);
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($binary, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= $alphabet[bindec($chunk)];
        }

        return $output;
    }

    private static function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/\s+/', '', $secret) ?? '');
        $binary = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $output .= chr(bindec($chunk));
        }

        return $output;
    }
}
