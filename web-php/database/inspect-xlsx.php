<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php database/inspect-xlsx.php <file.xlsx>\n");
    exit(1);
}

$spreadsheet = IOFactory::load($path);
$cols = ['AK', 'AL', 'AM', 'AN', 'AO', 'AP', 'AQ', 'AR', 'AS', 'AT', 'B', 'J', 'U', 'V'];
foreach ($spreadsheet->getSheetNames() as $name) {
    echo "=== Sheet: {$name} ===\n";
    if (!preg_match('/invent/i', $name)) {
        continue;
    }
    $sheet = $spreadsheet->getSheetByName($name);
    for ($row = 1; $row <= 15; $row++) {
        $parts = ["row {$row}"];
        foreach ($cols as $col) {
            $val = trim((string) $sheet->getCell($col . $row)->getCalculatedValue());
            if ($val !== '') {
                $parts[] = "{$col}={$val}";
            }
        }
        if (count($parts) > 1) {
            echo implode(' | ', $parts) . "\n";
        }
    }
}
