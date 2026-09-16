<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\SessionAuth;

$user = SessionAuth::user();
if ($user === null || ($user['role'] ?? '') !== 'administrador') {
    header('Location: ' . url('index.php') . '?login=1');
    exit;
}

header('Location: ' . url('index.php') . '?users=1');
exit;
