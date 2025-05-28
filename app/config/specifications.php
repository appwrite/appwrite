<?php

use Appwrite\Platform\Modules\Compute\Specification;

return [
    Specification::S_05VCPU_512MB => [
        'slug' => Specification::S_05VCPU_512MB,
        'memory' => 512,
        'cpus' => 0.5
    ],
    Specification::S_1VCPU_512MB => [
        'slug' => Specification::S_1VCPU_512MB,
        'memory' => 512,
        'cpus' => 1
    ],
    Specification::S_1VCPU_1GB => [
        'slug' => Specification::S_1VCPU_1GB,
        'memory' => 1024,
        'cpus' => 1
    ],
    Specification::S_2VCPU_2GB => [
        'slug' => Specification::S_2VCPU_2GB,
        'memory' => 2048,
        'cpus' => 2
    ],
    Specification::S_2VCPU_4GB => [
        'slug' => Specification::S_2VCPU_4GB,
        'memory' => 4096,
        'cpus' => 2
    ],
    Specification::S_4VCPU_4GB => [
        'slug' => Specification::S_4VCPU_4GB,
        'memory' => 4096,
        'cpus' => 4
    ],
    Specification::S_4VCPU_8GB => [
        'slug' => Specification::S_4VCPU_8GB,
        'memory' => 8192,
        'cpus' => 4
    ],
    Specification::S_8VCPU_4GB => [
        'slug' => Specification::S_8VCPU_4GB,
        'memory' => 4096,
        'cpus' => 8
    ],
    Specification::S_8VCPU_8GB => [
        'slug' => Specification::S_8VCPU_8GB,
        'memory' => 8192,
        'cpus' => 8
    ]
];
