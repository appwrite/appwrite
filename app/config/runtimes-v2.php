<?php

/**
 * List of Appwrite Cloud Functions supported runtimes
 */

use Appwrite\Runtimes\Runtimes;
use Utopia\System\System;

$runtimes = new Runtimes('v2');

$allowList = empty(System::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', System::getEnv('_APP_FUNCTIONS_RUNTIMES'));

$runtimes = $runtimes->getAll(true, $allowList);

return $runtimes;
