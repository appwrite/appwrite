<?php

/**
 * List of Appwrite Cloud Functions supported runtimes
 */

use Utopia\App;
use Appwrite\Runtimes\Runtimes;

$runtimes = new Runtimes('v3');

$allowList = empty(App::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', App::getEnv('_APP_FUNCTIONS_RUNTIMES'));

$runtimes = $runtimes->getAll(true, $allowList);

$runtimes['php-8.1']['image'] = 'meldiron/php:v3-8.1';

return $runtimes;
