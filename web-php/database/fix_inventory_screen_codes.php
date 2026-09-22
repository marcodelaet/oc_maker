<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use OcMaker\InventoryScreenCodeMigration;

echo "Corrigindo códigos de tela (sufixo da denominação, com ou sem ' - ')...\n";

try {
    $result = (new InventoryScreenCodeMigration())->run();
    echo "Atualizados: {$result['updated']}\n";
    echo "Ignorados: {$result['skipped']}\n";
    if ($result['conflicts'] !== []) {
        echo "Conflitos (" . count($result['conflicts']) . "):\n";
        foreach ($result['conflicts'] as $line) {
            echo "  - {$line}\n";
        }
    } else {
        echo "Nenhum conflito.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
