<?php

require_once __DIR__ . '/enum.php';

return [
    Sizes::S_1VCPU_512MB->value => [
        'memory' => 512,
        'cpus' => 1
    ],
    Sizes::S_1VCPU_1GB->value => [
        'memory' => 1024,
        'cpus' => 1
    ],
    Sizes::S_2VCPU_1GB->value => [
        'memory' => 1024,
        'cpus' => 2
    ],
    Sizes::S_2VCPU_4GB->value => [
        'memory' => 4096,
        'cpus' => 2
    ],
];
