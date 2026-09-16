<?php

declare(strict_types=1);

namespace OcMaker;

final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /** @return list<array{id:string,label:string}> */
    public static function rules(): array
    {
        return [
            ['id' => 'length', 'label' => 'Mínimo 8 caracteres'],
            ['id' => 'upper', 'label' => 'Letra maiúscula'],
            ['id' => 'lower', 'label' => 'Letra minúscula'],
            ['id' => 'digit', 'label' => 'Número'],
            ['id' => 'special', 'label' => 'Caractere especial'],
            ['id' => 'nospace', 'label' => 'Sem espaços em branco'],
        ];
    }

    /** @return array<string, bool> */
    public static function check(string $password): array
    {
        return [
            'length' => mb_strlen($password) >= self::MIN_LENGTH,
            'upper' => (bool) preg_match('/[A-Z\xC0-\xDD]/u', $password),
            'lower' => (bool) preg_match('/[a-z\xE0-\xFF]/u', $password),
            'digit' => (bool) preg_match('/\d/u', $password),
            'special' => (bool) preg_match('/[^A-Za-z0-9\s]/u', $password),
            'nospace' => !preg_match('/\s/u', $password),
        ];
    }

    public static function isValid(string $password): bool
    {
        foreach (self::check($password) as $ok) {
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    public static function assertValid(string $password): void
    {
        if (!self::isValid($password)) {
            throw new \RuntimeException('A senha não atende aos requisitos de segurança.');
        }
    }

    public static function generate(int $length = 8): string
    {
        $length = max(self::MIN_LENGTH, $length);
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $special = '!@#$%&*-_+=';
        $all = $upper . $lower . $digits . $special;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $special[random_int(0, strlen($special) - 1)],
        ];

        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($chars);

        return implode('', $chars);
    }
}
