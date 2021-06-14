<?php

use Utopia\App;
use Utopia\System\System;
use Appwrite\Runtimes\Runtimes;
use Appwrite\Runtimes\Runtime;

/**
 * List of Appwrite Cloud Functions supported runtimes
 */
$runtimes = new Runtimes();

/**
 * Load custom runtimes
 */ 
$customRuntimes = empty(App::getEnv('_APP_FUNCTIONS_CUSTOM_RUNTIMES')) ? [] : \explode(',', App::getEnv('_APP_FUNCTIONS_CUSTOM_RUNTIMES'));

$uniqueRuntimeNames = array();
foreach ($customRuntimes as $customRuntimeName) {
    $runtimeImageName;
    $runtimeImageVersion;

    if(strpos($customRuntimeName, ':') !== false) {
        $runtimeNameComponents = explode(":", $customRuntimeName);
        // [0] = name [1] = version

        $runtimeImageName = $runtimeNameComponents[0];
        $runtimeImageVersion = $runtimeNameComponents[1];
    } else {
        $runtimeImageName = $customRuntimeName;
        $runtimeImageVersion = "latest";
    }

    if(!isset($uniqueRuntimeNames[$runtimeImageName])) {
        $uniqueRuntimeNames[$runtimeImageName] = array($runtimeImageVersion);
    } else {
        if(!in_array($runtimeImageVersion, $uniqueRuntimeNames[$runtimeImageName])) {
            array_push($uniqueRuntimeNames[$runtimeImageName], $runtimeImageVersion);
        }
    }
}

foreach($uniqueRuntimeNames as $runtimeImageName => $runtimeImageVersions) {
    $customRuntime = new Runtime($runtimeImageName, $runtimeImageName);
    $customRuntime->setCustom(true);

    foreach ($runtimeImageVersions as $runtimeImageVersion) {
        // TODO: Not sure what are X64, PPC, ARM.. What should I do with these in terms of custom images?
        $customRuntime->addVersion($runtimeImageVersion, $runtimeImageName . ':' . $runtimeImageVersion, $runtimeImageName . ':' . $runtimeImageVersion, [System::X86, System::PPC, System::ARM]);
    }

    $runtimes->add($customRuntime);
}

/**
 * Load default runtimes supported by Appwrite by default
 */
$allowList = empty(App::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', App::getEnv('_APP_FUNCTIONS_RUNTIMES'));

$runtimes = $runtimes->getAll(true, $allowList);

return $runtimes;