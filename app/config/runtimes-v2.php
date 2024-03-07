<?php

/**
 * List of Appwrite Cloud Functions supported runtimes
 */

use Appwrite\Runtimes\Runtimes;
use Utopia\Http\Http;

$runtimes = new Runtimes('v2');

$allowList = empty(Http::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', Http::getEnv('_APP_FUNCTIONS_RUNTIMES'));

$runtimes = $runtimes->getAll(true, $allowList);

return $runtimes;
