<?php

use Utopia\App;
use Appwrite\Runtimes\Runtimes;

/**
 * List of Appwrite Cloud Functions supported runtimes
 */
$runtimes = new Runtimes();

$allowList = empty(App::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', App::getEnv('_APP_FUNCTIONS_RUNTIMES'));

$runtimes = $runtimes->getAll(filter: $allowList);

return $runtimes;