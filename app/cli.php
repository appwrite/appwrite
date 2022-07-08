<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\Task\Tasks;
use Utopia\Database\Validator\Authorization;

Authorization::disable();

$tasks = new Tasks();
$tasks
    ->init()
    ->run();

