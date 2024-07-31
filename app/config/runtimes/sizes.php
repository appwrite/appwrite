<?php

require_once __DIR__ . '/enum.php';

return [
    Sizes::S_1VCPU_512MB->value => [
        'slug' => 's-1vcpu-512mb',
        'memory' => 512,
        'cpus' => 1
    ],
    Sizes::S_1VCPU_1GB->value => [
        'slug' => 's-1vcpu-1gb',
        'memory' => 1024,
        'cpus' => 1
    ],
    Sizes::S_2VCPU_2GB->value => [
        'slug' => 's-2vcpu-2gb',
        'memory' => 2048,
        'cpus' => 2
    ],
    Sizes::S_2VCPU_4GB->value => [
        'slug' => 's-2vcpu-4gb',
        'memory' => 4096,
        'cpus' => 2
    ],
];
