<?php

use Utopia\App;
use Appwrite\Runtimes\Runtimes;

/**
 * List of Appwrite Cloud Functions supported runtimes
 */
$runtimes = Runtimes::get();

$allowList = empty(App::getEnv('_APP_FUNCTIONS_ENVS', null)) ? false : \explode(',', App::getEnv('_APP_FUNCTIONS_ENVS', null));

$runtimes = array_filter($runtimes, function ($key) use ($allowList) {
    $isAllowed = $allowList && in_array($key, $allowList);

    return $isAllowed;
}, ARRAY_FILTER_USE_BOTH);

return $runtimes;