<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

$schemaFile = $root . '/database/schema.sql';
if (!is_file($schemaFile)) {
    fwrite(STDERR, "Arquivo schema.sql não encontrado.\n");
    exit(1);
}

$sql = file_get_contents($schemaFile);
if ($sql === false) {
    fwrite(STDERR, "Falha ao ler schema.sql.\n");
    exit(1);
}

$host = dbHost();
$port = dbPort();
$user = dbUser();
$pass = dbPass();

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );

    foreach (array_filter(array_map('trim', preg_split('/;\s*\R/', $sql))) as $statement) {
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        $pdo->exec($statement);
    }

    echo "Schema aplicado com sucesso.\n";

    $migration = $root . '/database/migrate_20260915_auth_inventory.sql';
    if (is_file($migration)) {
        $migSql = file_get_contents($migration);
        if ($migSql !== false) {
            foreach (array_filter(array_map('trim', preg_split('/;\s*\R/', $migSql))) as $statement) {
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    if (!str_contains($e->getMessage(), 'Duplicate')) {
                        throw $e;
                    }
                }
            }
            echo "Migration auth/inventário aplicada.\n";
        }
    }

    $usersRepairMigration = $root . '/database/migrate_20260915_users_repair.sql';
    if (is_file($usersRepairMigration)) {
        $migSql = file_get_contents($usersRepairMigration);
        if ($migSql !== false) {
            foreach (array_filter(array_map('trim', preg_split('/;\s*\R/', $migSql))) as $statement) {
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    if (!str_contains($e->getMessage(), 'Duplicate column')
                        && !str_contains($e->getMessage(), 'Duplicate key name')) {
                        throw $e;
                    }
                }
            }
            echo "Migration users_repair aplicada.\n";
        }
    }

    $avatarMigration = $root . '/database/migrate_20260915_user_avatar.sql';
    if (is_file($avatarMigration)) {
        $migSql = file_get_contents($avatarMigration);
        if ($migSql !== false) {
            foreach (array_filter(array_map('trim', preg_split('/;\s*\R/', $migSql))) as $statement) {
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    if (!str_contains($e->getMessage(), 'Duplicate column')) {
                        throw $e;
                    }
                }
            }
            echo "Migration avatar aplicada.\n";
        }
    }

    $profileMigration = $root . '/database/migrate_20260915_user_profile.sql';
    if (is_file($profileMigration)) {
        $migSql = file_get_contents($profileMigration);
        if ($migSql !== false) {
            foreach (array_filter(array_map('trim', preg_split('/;\s*\R/', $migSql))) as $statement) {
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    if (!str_contains($e->getMessage(), 'Duplicate column')
                        && !str_contains($e->getMessage(), 'Duplicate key name')) {
                        throw $e;
                    }
                }
            }
            echo "Migration perfil aplicada.\n";
            (new \OcMaker\UserRepository())->dedupeUsernames();
        }
    }

    $mustChangeMigration = $root . '/database/migrate_20260915_must_change_password.sql';
    if (is_file($mustChangeMigration)) {
        $migSql = file_get_contents($mustChangeMigration);
        if ($migSql !== false) {
            foreach (array_filter(array_map('trim', preg_split('/;\s*\R/', $migSql))) as $statement) {
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    if (!str_contains($e->getMessage(), 'Duplicate column')) {
                        throw $e;
                    }
                }
            }
            echo "Migration must_change_password aplicada.\n";
        }
    }

    $activityLogMigration = $root . '/database/migrate_20260915_user_activity_log.sql';
    if (is_file($activityLogMigration)) {
        $migSql = file_get_contents($activityLogMigration);
        if ($migSql !== false) {
            foreach (array_filter(array_map('trim', preg_split('/;\s*\R/', $migSql))) as $statement) {
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    if (!str_contains($e->getMessage(), 'already exists')) {
                        throw $e;
                    }
                }
            }
            echo "Migration user_activity_log aplicada.\n";
        }
    }

    $inventoryRedeMigration = $root . '/database/migrate_20260915_document_inventory_rede.sql';
    if (is_file($inventoryRedeMigration)) {
        $migSql = file_get_contents($inventoryRedeMigration);
        if ($migSql !== false) {
            foreach (array_filter(array_map('trim', preg_split('/;\s*\R/', $migSql))) as $statement) {
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    if (!str_contains($e->getMessage(), 'Duplicate column')) {
                        throw $e;
                    }
                }
            }
            echo "Migration document_inventory_rede aplicada.\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro ao aplicar schema: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Verifique se o MySQL/MariaDB está em execução e se {$host}:{$port} está acessível.\n");
    fwrite(STDERR, "Diagnóstico: php database/test-connection.php\n");
    exit(1);
}
