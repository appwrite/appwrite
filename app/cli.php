<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\Task\Tasks;
use Utopia\Database\Validator\Authorization;

Authorization::disable();

Tasks::init();
Tasks::getCli()->run();

