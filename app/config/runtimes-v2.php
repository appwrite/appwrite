<?php

/**
 * List of Appwrite Cloud Functions supported runtimes
 */

use Utopia\Http\Http;
use Appwrite\Runtimes\Runtimes;

$runtimes = new Runtimes('v2');

$allowList = empty(Http::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', Http::getEnv('_APP_FUNCTIONS_RUNTIMES'));

$runtimes = $runtimes->getAll(true, $allowList);

return $runtimes;
