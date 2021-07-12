<?php

use Utopia\App;
use Appwrite\Runtimes\Runtimes;

/**
 * List of Appwrite Cloud Functions supported runtimes
 */
$runtimes = new Runtimes();

$allowList = \explode(',', App::getEnv('_APP_FUNCTIONS_RUNTIMES', 'node-16.0,php-8.0,python-3.9,ruby-3.0,java-16.0'));

$runtimes = $runtimes->getAll(true, $allowList);

return $runtimes;