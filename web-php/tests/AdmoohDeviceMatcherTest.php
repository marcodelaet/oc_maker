<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use OcMaker\AdmoohDeviceMatcher;

$known = [
    '1204-PDA-T01-JOAQUIM FLORIANO',
    '1289-PDA-T01-TATUAPÉ',
    '1289-PDA-T02-TATUAPÉ',
    '1214-PDA-T01-SÓCRATES',
];

$cases = [
    '1204-PDA-T01-JOAQUIM FLORIANO | Face1' => '1204-PDA-T01-JOAQUIM FLORIANO',
    '1214-PDA-T01-SÓCRATES_App' => '1214-PDA-T01-SÓCRATES',
    '1289-PDA-T02-TATUAPÉ | Face1' => '1289-PDA-T02-TATUAPÉ',
];

$failed = 0;
foreach ($cases as $device => $expected) {
    $matched = AdmoohDeviceMatcher::match($device, $known);
    if ($matched !== $expected) {
        echo "FAIL {$device}: got " . var_export($matched, true) . " expected {$expected}\n";
        $failed++;
    } else {
        echo "OK {$device}\n";
    }
}

exit($failed > 0 ? 1 : 0);
