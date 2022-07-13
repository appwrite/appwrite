<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\Task\CLIPlatform;
use Utopia\Database\Validator\Authorization;

Authorization::disable();

$cliPlatform = new CLIPlatform();
$cliPlatform->init('CLI');

$cli = $cliPlatform->getCli();
$cli->run();

