<?php

use Utopia\App;
use Appwrite\Runtimes\Runtimes;

/**
 * List of Appwrite Cloud Functions supported runtimes
 */
$runtimes = new Runtimes();

$allowList = empty(App::getEnv('_APP_FUNCTIONS_ENVS')) ? false : \explode(',', App::getEnv('_APP_FUNCTIONS_ENVS'));

$runtimes = array_filter($runtimes->getAll(), function ($key) use ($allowList) {
    $isAllowed = $allowList && in_array($key, $allowList);

    return $allowList ? $isAllowed : true;
}, ARRAY_FILTER_USE_BOTH);

return $runtimes;