<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
readfile(dirname(__DIR__, 2) . '/config/tech_fees.json');
