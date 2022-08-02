<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\CLI\Tasks;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Service;

Authorization::disable();

$cliPlatform = new Tasks();
$cliPlatform->init(Service::TYPE_CLI);

$cli = $cliPlatform->getCli();
$cli->run();
