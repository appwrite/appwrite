<?php

/**
 * List of Appwrite Cloud Functions supported runtimes
 */

use Appwrite\Runtimes\Runtimes;
use Utopia\App;

$runtimes = new Runtimes('v2');

$allowList = empty(App::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', App::getEnv('_APP_FUNCTIONS_RUNTIMES'));

$runtimes = $runtimes->getAll(true, $allowList);

return $runtimes;
