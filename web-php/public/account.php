<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use OcMaker\AuthService;

$user = (new AuthService())->currentUser();
if ($user === null) {
    header('Location: ' . url('index.php') . '?login=1');
    exit;
}

header('Location: ' . url('index.php') . '?account=1');
exit;
