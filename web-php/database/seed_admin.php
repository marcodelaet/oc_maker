<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use OcMaker\UserProfileService;
use OcMaker\UserRepository;

$email = $argv[1] ?? 'admin@retailmedia.local';
$password = $argv[2] ?? 'Admin123!';
$name = $argv[3] ?? 'Administrador';
$preferredUsername = null;
$promoteExisting = false;

foreach (array_slice($argv, 4), as $arg) {
    if ($arg === '--promote') {
        $promoteExisting = true;
        continue;
    }
    if ($preferredUsername === null) {
        $preferredUsername = $arg;
    }
}

function resolveAvailableUsername(UserRepository $repo, string $email, ?string $preferred = null): string
{
    if ($preferred !== null && $preferred !== '') {
        $username = UserProfileService::normalizeUsername($preferred);
        UserProfileService::assertUsernameFormat($username);
        if ($repo->findByUsername($username) === null) {
            return $username;
        }
        throw new RuntimeException("Nome de usuário já em uso: {$username}");
    }

    $base = explode('@', $email)[0] ?? 'user';
    $base = preg_replace('/[^a-z0-9_.]/', '', UserProfileService::normalizeUsername($base)) ?? '';
    if (strlen($base) < 3) {
        $base = 'user' . substr(md5($email), 0, 5);
    }
    $base = substr($base, 0, 60);

    $candidate = $base;
    $suffix = 2;
    while ($repo->findByUsername($candidate) !== null) {
        $candidate = $base . $suffix;
        $suffix++;
        if ($suffix > 9999) {
            throw new RuntimeException('Não foi possível gerar um nome de usuário único.');
        }
    }

    return $candidate;
}

$repo = new UserRepository();
$existing = $repo->findByEmail($email);
if ($existing !== null) {
    $role = (string) ($existing['role'] ?? '');
    echo "Usuário já existe: {$email}\n";
    echo "Role atual: {$role}\n";
    if (!empty($existing['username'])) {
        echo "Username: {$existing['username']}\n";
    }

    if ($promoteExisting && $role !== 'administrador') {
        $repo->update((int) $existing['id'], ['role' => 'administrador']);
        echo "Promovido para administrador.\n";
    } elseif ($role !== 'administrador') {
        echo "Para promover: php database/seed_admin.php {$email} '' '' --promote\n";
    }

    exit(0);
}

try {
    $username = resolveAvailableUsername($repo, $email, $preferredUsername);
    $id = $repo->create([
        'email' => $email,
        'password' => $password,
        'name' => $name,
        'role' => 'administrador',
        'username' => $username,
        'must_change_password' => true,
    ]);

    echo "Administrador criado (id={$id}): {$email}\n";
    echo "Username: {$username}\n";
    echo "Altere a senha após o primeiro login.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro ao criar administrador: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
