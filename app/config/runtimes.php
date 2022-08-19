<?php

/**
 * List of Appwrite Cloud Functions supported runtimes
 */

use Utopia\App;
use Appwrite\Runtimes\Runtimes;

$runtimes = new Runtimes('v2');

$allowList = empty(App::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', App::getEnv('_APP_FUNCTIONS_RUNTIMES'));

$runtimes = $runtimes->getAll(true, $allowList);

return $runtimes;
