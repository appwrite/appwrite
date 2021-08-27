<?php

use Utopia\App;
use Appwrite\Runtimes\Runtimes;
use Appwrite\Runtimes\Runtime;
use Utopia\System\System;

/**
 * List of Appwrite Cloud Functions supported runtimes
 */
$runtimes = new Runtimes();

$allowList = empty(App::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', App::getEnv('_APP_FUNCTIONS_RUNTIMES'));

$node = new Runtime('node', 'Node.js');
$node->addVersion('NG-Latest', 'node:16-alpine-nx', 'node-runtime', [System::X86, System::PPC, System::ARM]);

$runtimes->add($node);

$runtimes = $runtimes->getAll(true, $allowList);

return $runtimes;