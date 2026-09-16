<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use OcMaker\Database;

echo "=== OC Maker — usuários e perfis ===\n";

try {
    $rows = Database::connection()->query(
        'SELECT id, email, username, role, active, must_change_password FROM users ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($rows === []) {
        echo "Nenhum usuário encontrado.\n";
        exit(0);
    }

    foreach ($rows as $row) {
        echo sprintf(
            "#%d  %s  (%s)  role=%s  active=%s  must_change_password=%s\n",
            (int) $row['id'],
            (string) $row['email'],
            (string) ($row['username'] ?? '—'),
            (string) $row['role'],
            ((int) $row['active']) === 1 ? 'sim' : 'não',
            ((int) $row['must_change_password']) === 1 ? 'sim' : 'não',
        );
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
