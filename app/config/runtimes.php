<?php

use Utopia\App;
use Appwrite\Runtimes\Runtimes;

/**
 * List of Appwrite Cloud Functions supported runtimes
 */
$runtimes = new Runtimes('v1');

$allowList = empty(App::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', App::getEnv('_APP_FUNCTIONS_RUNTIMES'));

$runtimes = $runtimes->getAll(true, $allowList);

return $runtimes;