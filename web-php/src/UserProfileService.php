<?php

declare(strict_types=1);

namespace OcMaker;

final class UserProfileService
{
    /** @var list<array{code:string,label:string,dial:string,flag:string}> */
    public const COUNTRY_CODES = [
        ['code' => 'BR', 'label' => 'Brasil', 'dial' => '+55', 'flag' => '🇧🇷'],
        ['code' => 'US', 'label' => 'Estados Unidos', 'dial' => '+1', 'flag' => '🇺🇸'],
        ['code' => 'PT', 'label' => 'Portugal', 'dial' => '+351', 'flag' => '🇵🇹'],
        ['code' => 'AR', 'label' => 'Argentina', 'dial' => '+54', 'flag' => '🇦🇷'],
        ['code' => 'CL', 'label' => 'Chile', 'dial' => '+56', 'flag' => '🇨🇱'],
        ['code' => 'CO', 'label' => 'Colômbia', 'dial' => '+57', 'flag' => '🇨🇴'],
        ['code' => 'MX', 'label' => 'México', 'dial' => '+52', 'flag' => '🇲🇽'],
        ['code' => 'ES', 'label' => 'Espanha', 'dial' => '+34', 'flag' => '🇪🇸'],
        ['code' => 'GB', 'label' => 'Reino Unido', 'dial' => '+44', 'flag' => '🇬🇧'],
    ];

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /** @param array<string, mixed> $data */
    public function updateProfile(int $userId, array $data): array
    {
        $current = $this->users->findById($userId);
        if ($current === null) {
            throw new \RuntimeException('Usuário não encontrado.');
        }

        $payload = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new \RuntimeException('Nome é obrigatório.');
            }
            $payload['name'] = sanitizeString($name, 120);
        }

        if (array_key_exists('last_name', $data)) {
            $lastName = trim((string) $data['last_name']);
            $payload['last_name'] = $lastName === '' ? null : sanitizeString($lastName, 120);
        }

        if (array_key_exists('birth_date', $data)) {
            $payload['birth_date'] = self::parseBirthDate($data['birth_date']);
        }

        if (array_key_exists('username', $data)) {
            $payload['username'] = self::normalizeUsername((string) $data['username']);
            if ($payload['username'] === '') {
                throw new \RuntimeException('Nome de usuário é obrigatório.');
            }
            self::assertUsernameFormat($payload['username']);
            $this->users->assertUsernameAvailable($payload['username'], $userId);
        }

        if (array_key_exists('email', $data)) {
            $email = mb_strtolower(trim((string) $data['email']));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('E-mail inválido.');
            }
            $payload['email'] = sanitizeString($email, 190);
            $this->users->assertEmailAvailable($payload['email'], $userId);
        }

        if (array_key_exists('phone_country_code', $data) || array_key_exists('phone', $data)) {
            $country = self::normalizeCountryCode((string) ($data['phone_country_code'] ?? $current['phone_country_code'] ?? '+55'));
            $phoneRaw = trim((string) ($data['phone'] ?? $current['phone'] ?? ''));
            if ($phoneRaw === '') {
                $payload['phone_country_code'] = '+55';
                $payload['phone'] = null;
            } else {
                $payload['phone_country_code'] = $country;
                $payload['phone'] = self::normalizeNationalPhone($phoneRaw);
                $this->users->assertPhoneAvailable($payload['phone_country_code'], $payload['phone'], $userId);
            }
        }

        if ($payload === []) {
            return $this->users->publicUser($current);
        }

        try {
            $this->users->updateProfile($userId, $payload);
        } catch (\PDOException $e) {
            throw self::mapDuplicateError($e);
        }

        $updated = $this->users->findById($userId);
        if ($updated === null) {
            throw new \RuntimeException('Usuário não encontrado.');
        }

        SessionAuth::refreshUser($updated);

        return $this->users->publicUser($updated);
    }

    public static function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    public static function assertUsernameFormat(string $username): void
    {
        if (!preg_match('/^[a-z0-9._]{3,64}$/', $username)) {
            throw new \RuntimeException('Nome de usuário deve ter 3–64 caracteres (letras minúsculas, números, _ ou .).');
        }
    }

    public static function normalizeCountryCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '+55';
        }
        if (!str_starts_with($code, '+')) {
            $code = '+' . ltrim($code, '+');
        }
        if (!preg_match('/^\+\d{1,4}$/', $code)) {
            throw new \RuntimeException('Código de país inválido.');
        }

        return $code;
    }

    public static function normalizeNationalPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '' || strlen($digits) < 8 || strlen($digits) > 15) {
            throw new \RuntimeException('Número de telefone inválido.');
        }

        return $digits;
    }

    /** @return string|null Y-m-d */
    public static function parseBirthDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) {
            $raw = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($dt === false || $dt->format('Y-m-d') !== $raw) {
            throw new \RuntimeException('Data de nascimento inválida.');
        }
        if ($dt > new \DateTimeImmutable('today')) {
            throw new \RuntimeException('Data de nascimento não pode ser no futuro.');
        }

        return $raw;
    }

    public static function parseLoginIdentifier(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            throw new \RuntimeException('Informe usuário, e-mail ou telefone.');
        }

        if (str_contains($input, '@')) {
            return ['type' => 'email', 'value' => mb_strtolower($input)];
        }

        $compact = preg_replace('/[\s().-]/', '', $input) ?? $input;
        if (str_starts_with($compact, '+') || preg_match('/^\d{8,15}$/', $compact)) {
            return ['type' => 'phone', 'value' => self::parsePhoneLogin($compact)];
        }

        return ['type' => 'username', 'value' => self::normalizeUsername($input)];
    }

    /** @return array{country:string,national:string} */
    public static function parsePhoneLogin(string $compact): array
    {
        $digits = preg_replace('/\D+/', '', $compact) ?? '';
        if ($digits === '') {
            throw new \RuntimeException('Telefone inválido.');
        }

        foreach (self::COUNTRY_CODES as $entry) {
            $dialDigits = ltrim($entry['dial'], '+');
            if (str_starts_with($digits, $dialDigits) && strlen($digits) > strlen($dialDigits) + 7) {
                return [
                    'country' => $entry['dial'],
                    'national' => substr($digits, strlen($dialDigits)),
                ];
            }
        }

        if (strlen($digits) >= 10 && strlen($digits) <= 11 && !str_starts_with($compact, '+')) {
            return ['country' => '+55', 'national' => $digits];
        }

        throw new \RuntimeException('Telefone inválido. Informe com código do país (ex.: +55).');
    }

    public static function mapDuplicateError(\PDOException $e): \RuntimeException
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'uk_users_email')) {
            return new \RuntimeException('Este e-mail já está em uso.');
        }
        if (str_contains($msg, 'uk_users_username')) {
            return new \RuntimeException('Este nome de usuário já está em uso.');
        }
        if (str_contains($msg, 'uk_users_phone')) {
            return new \RuntimeException('Este telefone já está em uso.');
        }

        return new \RuntimeException('Não foi possível salvar: valor duplicado.');
    }
}
