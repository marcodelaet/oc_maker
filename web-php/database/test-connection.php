<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use OcMaker\Database;

echo "Testando conexão MySQL (mesma lógica da aplicação web)...\n";
echo '  SAPI: ' . PHP_SAPI . "\n";
echo '  Host configurado: ' . dbHost() . "\n";
echo '  Porta: ' . dbPort() . "\n";
echo '  Banco: ' . dbName() . "\n";
echo '  Usuário: ' . dbUser() . "\n\n";

try {
    Database::connection()->query('SELECT 1');
    echo "Conexão OK.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Falhou: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
