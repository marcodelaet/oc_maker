<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__);
$dirs = [
    $root . '/storage/uploads',
    $root . '/storage/avatars',
    $root . '/storage/spreadsheets',
    $root . '/storage/spreadsheets/campaign-cache',
    $root . '/storage/pdf',
    $root . '/storage/creatives',
];

$failed = false;
$mode = PHP_OS_FAMILY === 'Windows' ? 0777 : 0775;

foreach ($dirs as $dir) {
    if (!is_dir($dir) && !mkdir($dir, $mode, true) && !is_dir($dir)) {
        fwrite(STDERR, "Falha ao criar: {$dir}\n");
        $failed = true;
        continue;
    }

    $writable = storageDirectoryWritable($dir);
    echo ($writable ? '[OK] ' : '[!!] ') . $dir . PHP_EOL;
    if (!$writable) {
        $failed = true;
    }
}

if ($failed) {
    fwrite(STDERR, PHP_EOL . storagePermissionHint() . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Storage pronto para uploads, PDFs e criativos.' . PHP_EOL;
