<?php

const APP_PLATFORM_WEB = 'web';
const APP_PLATFORM_IOS = 'ios';
const APP_PLATFORM_ANDROID = 'android';
const APP_PLATFORM_UNITY = 'unity';
const APP_PLATFORM_FLUTTER = 'flutter';
const APP_PLATFORM_SERVER = 'server';

return [
    APP_PLATFORM_WEB => [
        'name' => 'Web',
        'enabled' => true,
        'beta' => false,
        'language' => [
            [
                'name' => 'JS',
                'repository' => '',
                'enabled' => true,
                'beta' => false,
            ],
            [
                'name' => 'TypeScript',
                'repository' => '',
                'enabled' => true,
                'beta' => false,
            ],
        ],
    ],
    
    APP_PLATFORM_IOS => [
        'name' => 'iOS',
        'enabled' => false,
        'beta' => false,
        'language' => [
            [
                'name' => 'Swift',
                'repository' => '',
                'enabled' => false,
                'beta' => false,
            ],
            [
                'name' => 'Objective C',
                'repository' => '',
                'enabled' => false,
                'beta' => false,
            ],
        ],
    ],

    APP_PLATFORM_ANDROID => [
        'name' => 'Android',
        'enabled' => false,
        'beta' => false,
        'language' => [
            [
                'name' => 'Swift',
                'repository' => '',
                'enabled' => false,
                'beta' => false,
            ],
            [
                'name' => 'Objective C',
                'repository' => '',
                'enabled' => false,
                'beta' => false,
            ],
        ],
    ],

    APP_PLATFORM_SERVER => [
        'name' => 'Server',
        'enabled' => true,
        'beta' => false,
        'language' => [
            [
                'name' => 'Node.js',
                'repository' => '',
                'enabled' => true,
                'beta' => false,
            ],
            [
                'name' => 'PHP',
                'repository' => '',
                'enabled' => true,
                'beta' => false,
            ],
            [
                'name' => 'Python',
                'repository' => '',
                'enabled' => true,
                'beta' => true,
            ],
            [
                'name' => 'Go',
                'repository' => '',
                'enabled' => true,
                'beta' => true,
            ],
            [
                'name' => 'Ruby',
                'repository' => '',
                'enabled' => true,
                'beta' => true,
            ],
        ],
    ],
    
];
