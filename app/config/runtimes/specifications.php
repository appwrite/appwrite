<?php

use Appwrite\Functions\Specifications;

return [
    Specifications::$S_1VCPU_512MB => [
        'slug' => 's-1vcpu-512mb',
        'memory' => 512,
        'cpus' => 1
    ],
    Specifications::$S_1VCPU_1GB => [
        'slug' => 's-1vcpu-1gb',
        'memory' => 1024,
        'cpus' => 1
    ],
    Specifications::$S_2VCPU_2GB => [
        'slug' => 's-2vcpu-2gb',
        'memory' => 2048,
        'cpus' => 2
    ],
    Specifications::$S_2VCPU_4GB => [
        'slug' => 's-2vcpu-4gb',
        'memory' => 4096,
        'cpus' => 2
    ],
];
