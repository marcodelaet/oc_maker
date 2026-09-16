<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use OcMaker\AuthService;

if ((new AuthService())->currentUser() !== null) {
    header('Location: ' . url('index.php'));
    exit;
}

header('Location: ' . url('index.php') . '?login=1');
exit;