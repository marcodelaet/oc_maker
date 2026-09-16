<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dirs = [
    $root . '/storage/uploads',
    $root . '/storage/avatars',
    $root . '/storage/spreadsheets',
    $root . '/storage/spreadsheets/campaign-cache',
    $root . '/storage/pdf',
];

$failed = false;

foreach ($dirs as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Falha ao criar: {$dir}\n");
        $failed = true;
        continue;
    }

    $writable = is_writable($dir);
    echo ($writable ? '[OK] ' : '[!!] ') . $dir . PHP_EOL;
    if (!$writable) {
        $failed = true;
    }
}

if ($failed) {
    fwrite(STDERR, PHP_EOL . "Ajuste permissões no servidor (exemplo Linux):\n");
    fwrite(STDERR, "  sudo chown -R www-data:www-data {$root}/storage\n");
    fwrite(STDERR, "  sudo chmod -R 775 {$root}/storage\n");
    exit(1);
}

echo PHP_EOL . 'Storage pronto para uploads e PDFs.' . PHP_EOL;
