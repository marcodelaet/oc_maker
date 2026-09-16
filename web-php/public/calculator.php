<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use OcMaker\SessionAuth;

$user = SessionAuth::user();
if ($user === null || !in_array($user['role'] ?? '', ['administrador', 'financeiro'], true)) {
    header('Location: ' . url('index.php') . '?login=1');
    exit;
}

header('Location: ' . url('index.php') . '?calculator=1');
exit;