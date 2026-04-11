<?php

/**
 * Project onboarding: each stage maps to an SDK method key (namespace + method name, same as Appwrite\SDK\Method).
 * The sdk index is built once for O(1) lookup in the API shutdown hook.
 */
$stages = [
    [
        'id' => 'create_database',
        'sdk' => 'databases.create',
    ],
    [
        'id' => 'create_bucket',
        'sdk' => 'storage.createBucket',
    ],
    [
        'id' => 'create_function',
        'sdk' => 'functions.create',
    ],
];

$sdkIndex = [];
foreach ($stages as $stage) {
    $sdkIndex[$stage['sdk']] = $stage['id'];
}

return [
    'stages' => $stages,
    'sdkIndex' => $sdkIndex,
];
