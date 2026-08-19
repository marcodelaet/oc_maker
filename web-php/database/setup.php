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
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro ao aplicar schema: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, "Verifique se o MySQL/MariaDB está em execução e se {$host}:{$port} está acessível.\n");
    fwrite(STDERR, "Diagnóstico: php database/test-connection.php\n");
    exit(1);
}
