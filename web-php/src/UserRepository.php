<?php



declare(strict_types=1);



namespace OcMaker;



use PDO;

use PDOException;



final class UserRepository

{

    /** @return array<string, mixed>|null */

    public function findById(int $id): ?array

    {

        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');

        $stmt->execute([$id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);



        return $row ?: null;

    }



    /** @return array<string, mixed>|null */

    public function findByEmail(string $email): ?array

    {

        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');

        $stmt->execute([mb_strtolower(trim($email))]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);



        return $row ?: null;

    }



    /** @return array<string, mixed>|null */

    public function findByUsername(string $username): ?array

    {

        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');

        $stmt->execute([UserProfileService::normalizeUsername($username)]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);



        return $row ?: null;

    }



    /** @return array<string, mixed>|null */

    public function findByPhone(string $countryCode, string $nationalPhone): ?array

    {

        $stmt = Database::connection()->prepare(

            'SELECT * FROM users WHERE phone_country_code = ? AND phone = ? LIMIT 1'

        );

        $stmt->execute([

            UserProfileService::normalizeCountryCode($countryCode),

            UserProfileService::normalizeNationalPhone($nationalPhone),

        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);



        return $row ?: null;

    }



    /** @return array<string, mixed>|null */

    public function findByLoginIdentifier(array $parsed): ?array

    {

        return match ($parsed['type']) {

            'email' => $this->findByEmail($parsed['value']),

            'username' => $this->findByUsername($parsed['value']),

            'phone' => $this->findByPhone($parsed['value']['country'], $parsed['value']['national']),

            default => null,

        };

    }



    /** @return list<array<string, mixed>> */

    public function listAll(): array

    {

        $stmt = Database::connection()->query(

            'SELECT id, email, username, name, last_name, birth_date, phone_country_code, phone,

                    avatar_path, role, totp_enabled, active, created_at, updated_at

             FROM users ORDER BY name'

        );



        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    }



    /** @param array{email:string,password:string,name:string,role:string,username?:string,last_name?:?string,phone_country_code?:string,phone?:?string} $data */

    public function create(array $data): int

    {

        $role = Permission::normalizeRole($data['role']);

        $email = mb_strtolower(trim($data['email']));

        $username = isset($data['username']) && $data['username'] !== ''

            ? UserProfileService::normalizeUsername((string) $data['username'])

            : UserProfileService::normalizeUsername(explode('@', $email)[0] ?? 'user');

        $username = preg_replace('/[^a-z0-9_.]/', '', $username) ?? '';

        if (strlen($username) < 3) {

            $username = 'user' . substr(md5($email), 0, 8);

        }

        UserProfileService::assertUsernameFormat($username);

        $this->assertEmailAvailable($email);

        $this->assertUsernameAvailable($username);



        $phoneCountry = UserProfileService::normalizeCountryCode((string) ($data['phone_country_code'] ?? '+55'));

        $phone = null;

        if (!empty($data['phone'])) {

            $phone = UserProfileService::normalizeNationalPhone((string) $data['phone']);

            $this->assertPhoneAvailable($phoneCountry, $phone);

        }



        try {

            $stmt = Database::connection()->prepare(

                'INSERT INTO users (email, username, password_hash, name, last_name, phone_country_code, phone, role, active, must_change_password)

                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'

            );

            $stmt->execute([

                $email,

                $username,

                password_hash($data['password'], PASSWORD_DEFAULT),

                trim($data['name']),

                isset($data['last_name']) && $data['last_name'] !== '' ? trim((string) $data['last_name']) : null,

                $phoneCountry,

                $phone,

                $role,

                !empty($data['must_change_password']) ? 1 : 0,

            ]);

        } catch (PDOException $e) {

            throw UserProfileService::mapDuplicateError($e);

        }



        return (int) Database::connection()->lastInsertId();

    }



    public function updatePassword(int $userId, string $password, bool $requireChangeOnNextLogin = false): void

    {

        PasswordPolicy::assertValid($password);

        $stmt = Database::connection()->prepare(

            'UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL, must_change_password = ? WHERE id = ?'

        );

        $stmt->execute([

            password_hash($password, PASSWORD_DEFAULT),

            $requireChangeOnNextLogin ? 1 : 0,

            $userId,

        ]);

    }



    public function setAvatarPath(int $userId, ?string $path): void

    {

        $stmt = Database::connection()->prepare('UPDATE users SET avatar_path = ? WHERE id = ?');

        $stmt->execute([$path, $userId]);

    }



    public function setTotpSecret(int $userId, ?string $secret, bool $enabled): void

    {

        $stmt = Database::connection()->prepare(

            'UPDATE users SET totp_secret = ?, totp_enabled = ? WHERE id = ?'

        );

        $stmt->execute([$secret, $enabled ? 1 : 0, $userId]);

    }



    /** @param array{name?:string,last_name?:?string,birth_date?:?string,username?:string,email?:string,phone_country_code?:string,phone?:?string,role?:string,active?:bool} $data */

    public function updateProfile(int $userId, array $data): void

    {

        $fields = [];

        $params = [];

        foreach ([

            'name' => 'name',

            'last_name' => 'last_name',

            'birth_date' => 'birth_date',

            'username' => 'username',

            'email' => 'email',

            'phone_country_code' => 'phone_country_code',

            'phone' => 'phone',

        ] as $key => $column) {

            if (!array_key_exists($key, $data)) {

                continue;

            }

            $fields[] = $column . ' = ?';

            $params[] = $data[$key];

        }

        if ($fields === []) {

            return;

        }

        $params[] = $userId;

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';

        Database::connection()->prepare($sql)->execute($params);

    }



    /** @param array{name?:string,email?:string,username?:string,role?:string,active?:bool} $data */

    public function update(int $userId, array $data): void

    {

        $fields = [];

        $params = [];

        if (isset($data['name'])) {

            $fields[] = 'name = ?';

            $params[] = trim((string) $data['name']);

        }

        if (array_key_exists('last_name', $data)) {

            $fields[] = 'last_name = ?';

            $params[] = $data['last_name'] !== null && $data['last_name'] !== ''

                ? trim((string) $data['last_name'])

                : null;

        }

        if (isset($data['email'])) {

            $email = mb_strtolower(trim((string) $data['email']));

            $this->assertEmailAvailable($email, $userId);

            $fields[] = 'email = ?';

            $params[] = $email;

        }

        if (isset($data['username'])) {

            $username = UserProfileService::normalizeUsername((string) $data['username']);

            UserProfileService::assertUsernameFormat($username);

            $this->assertUsernameAvailable($username, $userId);

            $fields[] = 'username = ?';

            $params[] = $username;

        }

        if (isset($data['role'])) {

            $fields[] = 'role = ?';

            $params[] = Permission::normalizeRole((string) $data['role']);

        }

        if (array_key_exists('active', $data)) {

            $fields[] = 'active = ?';

            $params[] = $data['active'] ? 1 : 0;

        }

        if ($fields === []) {

            return;

        }

        $params[] = $userId;

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';

        try {

            Database::connection()->prepare($sql)->execute($params);

        } catch (PDOException $e) {

            throw UserProfileService::mapDuplicateError($e);

        }

    }



    public function delete(int $userId): void

    {

        $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = ?');

        $stmt->execute([$userId]);

    }



    public function adminDisableTotp(int $userId): void

    {

        $this->setTotpSecret($userId, null, false);

    }



    public function assertEmailAvailable(string $email, ?int $excludeUserId = null): void

    {

        $existing = $this->findByEmail($email);

        if ($existing !== null && (int) $existing['id'] !== (int) ($excludeUserId ?? 0)) {

            throw new \RuntimeException('Este e-mail já está em uso.');

        }

    }



    public function assertUsernameAvailable(string $username, ?int $excludeUserId = null): void

    {

        $existing = $this->findByUsername($username);

        if ($existing !== null && (int) $existing['id'] !== (int) ($excludeUserId ?? 0)) {

            throw new \RuntimeException('Este nome de usuário já está em uso.');

        }

    }



    public function assertPhoneAvailable(string $countryCode, string $nationalPhone, ?int $excludeUserId = null): void

    {

        $existing = $this->findByPhone($countryCode, $nationalPhone);

        if ($existing !== null && (int) $existing['id'] !== (int) ($excludeUserId ?? 0)) {

            throw new \RuntimeException('Este telefone já está em uso.');

        }

    }



    public function recordFailedLogin(int $userId): void

    {

        $stmt = Database::connection()->prepare(

            'UPDATE users SET failed_logins = failed_logins + 1,

             locked_until = IF(failed_logins >= 4, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until)

             WHERE id = ?'

        );

        $stmt->execute([$userId]);

    }



    public function clearFailedLogins(int $userId): void

    {

        $stmt = Database::connection()->prepare(

            'UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?'

        );

        $stmt->execute([$userId]);

    }



    /** @param array<string, mixed> $user */

    public function publicUser(array $user): array

    {

        $firstName = (string) ($user['name'] ?? '');

        $lastName = (string) ($user['last_name'] ?? '');

        $displayName = trim($firstName . ($lastName !== '' ? ' ' . $lastName : ''));



        return [

            'id' => (int) $user['id'],

            'email' => $user['email'],

            'username' => $user['username'] ?? null,

            'name' => $firstName,

            'last_name' => $lastName !== '' ? $lastName : null,

            'display_name' => $displayName !== '' ? $displayName : ($user['email'] ?? 'Usuário'),

            'birth_date' => $user['birth_date'] ?? null,

            'phone_country_code' => $user['phone_country_code'] ?? '+55',

            'phone' => $user['phone'] ?? null,

            'role' => $user['role'],

            'avatar_url' => AvatarService::publicAvatarUrl($user),

            'totp_enabled' => (bool) ($user['totp_enabled'] ?? false),

            'active' => (bool) ($user['active'] ?? true),

            'must_change_password' => (bool) ($user['must_change_password'] ?? false),

        ];

    }



    public function dedupeUsernames(): void

    {

        $pdo = Database::connection();

        $rows = $pdo->query(

            'SELECT username, GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS c

             FROM users WHERE username IS NOT NULL AND username <> ""

             GROUP BY username HAVING c > 1'

        )->fetchAll(PDO::FETCH_ASSOC);



        foreach ($rows as $row) {

            $ids = array_map('intval', explode(',', (string) $row['ids']));

            array_shift($ids);

            foreach ($ids as $id) {

                $base = substr((string) $row['username'], 0, 50);

                $stmt = $pdo->prepare('UPDATE users SET username = ? WHERE id = ?');

                $stmt->execute([$base . $id, $id]);

            }

        }

    }

}

