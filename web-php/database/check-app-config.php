<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

echo "=== OC Maker — diagnóstico de URL base ===\n";
echo '.env encontrado: ' . (is_file($root . '/.env') ? 'sim' : 'não') . "\n";
echo 'config/app.local.php: ' . (is_file($root . '/config/app.local.php') ? 'sim' : 'não') . "\n";
echo 'APP_ENV (.env): ' . env('APP_ENV', '(vazio)') . "\n";
echo 'APP_BASE_PATH (.env): ' . env('APP_BASE_PATH', '(vazio)') . "\n";
echo 'base_path (config): ' . appConfig('base_path', '(vazio)') . "\n";
echo 'appBasePath(): ' . appBasePath() . "\n";
echo 'CSS exemplo: ' . assetUrl('assets/css/app.css') . "\n";
echo 'SCRIPT_NAME: ' . ($_SERVER['SCRIPT_NAME'] ?? '(n/a)') . "\n";
echo 'REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? '(n/a)') . "\n";
