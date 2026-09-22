<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use OcMaker\DealReportImportService;

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php database/test-report-import.php <report-file>\n");
    exit(1);
}

$service = new DealReportImportService();
$reflection = new ReflectionClass($service);
$parseFile = $reflection->getMethod('parseFile');
$parseFile->setAccessible(true);

/** @var array{platform: string, rows: list<array<string, mixed>>} $parsed */
$parsed = $parseFile->invoke($service, $path, basename($path));
$rows = $parsed['rows'];

echo 'Platform: ' . $parsed['platform'] . PHP_EOL;
echo 'Rows: ' . count($rows) . PHP_EOL;

$parseDate = $reflection->getMethod('parseDate');
$parseDate->setAccessible(true);
$parseNumber = $reflection->getMethod('parseNumber');
$parseNumber->setAccessible(true);
$parseCurrency = $reflection->getMethod('parseCurrency');
$parseCurrency->setAccessible(true);

if ($rows !== []) {
    $first = $rows[0];
    echo 'First row raw: ' . json_encode($first, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo 'First row normalized: ' . json_encode([
        'date' => $parseDate->invoke($service, $first['report_date'] ?? null, $parsed['platform']),
        'requisicoes' => $parseNumber->invoke($service, $first['requisicoes'] ?? null),
        'impressoes' => $parseNumber->invoke($service, $first['impressoes'] ?? null),
        'impactos' => $parseNumber->invoke($service, $first['impactos'] ?? null),
        'consumo' => $parseNumber->invoke($service, $first['consumo'] ?? null),
        'moeda' => $parseCurrency->invoke($service, $first['consumo'] ?? null, $first['moeda'] ?? 'BRL'),
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
