<?php

use Appwrite\Functions\Specification;

return [
    Specification::S_05VCPU_512MB => [
        'slug' => 's-05vcpu-512mb',
        'memory' => 512,
        'cpus' => 0.5
    ],
    Specification::S_1VCPU_512MB => [
        'slug' => 's-1vcpu-512mb',
        'memory' => 512,
        'cpus' => 1
    ],
    Specification::S_1VCPU_1GB => [
        'slug' => 's-1vcpu-1gb',
        'memory' => 1024,
        'cpus' => 1
    ],
    Specification::S_2VCPU_2GB => [
        'slug' => 's-2vcpu-2gb',
        'memory' => 2048,
        'cpus' => 2
    ],
    Specification::S_2VCPU_4GB => [
        'slug' => 's-2vcpu-4gb',
        'memory' => 4096,
        'cpus' => 2
    ],
    Specification::S_4VCPU_4GB => [
        'slug' => 's-4vcpu-4gb',
        'memory' => 4096,
        'cpus' => 4
    ],
    Specification::S_4VCPU_8GB => [
        'slug' => 's-4vcpu-8gb',
        'memory' => 8192,
        'cpus' => 4
    ],
    Specification::S_8VCPU_4GB => [
        'slug' => 's-8vcpu-4gb',
        'memory' => 4096,
        'cpus' => 8
    ],
    Specification::S_8VCPU_8GB => [
        'slug' => 's-8vcpu-8gb',
        'memory' => 8192,
        'cpus' => 8
    ]
];
