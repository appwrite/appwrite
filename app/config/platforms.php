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
                'repository' => 'https://github.com/appwrite/sdk-for-js',
                'enabled' => true,
                'beta' => false,
                'prism' => 'javascript',
                'source' => realpath(__DIR__ . '/../sdks/js'),
            ],
            [
                'name' => 'TypeScript',
                'repository' => '',
                'enabled' => true,
                'beta' => false,
                'prism' => 'typescript',
                'source' => '',
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
                'prism' => 'swift',
                'source' => '',
            ],
            [
                'name' => 'Objective C',
                'repository' => '',
                'enabled' => false,
                'beta' => false,
                'prism' => '',
                'source' => '',
            ],
        ],
    ],

    APP_PLATFORM_ANDROID => [
        'name' => 'Android',
        'enabled' => false,
        'beta' => false,
        'language' => [
            [
                'name' => 'Kotlin',
                'repository' => '',
                'enabled' => false,
                'beta' => false,
                'prism' => 'kotlin',
                'source' => false,
            ],
            [
                'name' => 'Java',
                'repository' => '',
                'enabled' => false,
                'beta' => false,
                'prism' => 'java',
                'source' => false,
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
                'repository' => 'https://github.com/appwrite/sdk-for-node',
                'enabled' => true,
                'beta' => false,
                'prism' => 'javascript',
                'source' => realpath(__DIR__ . '/../sdks/node'),
            ],
            [
                'name' => 'PHP',
                'repository' => 'https://github.com/appwrite/sdk-for-php',
                'enabled' => true,
                'prism' => 'php',
                'source' => realpath(__DIR__ . '/../sdks/php'),
            ],
            [
                'name' => 'Python',
                'repository' => 'https://github.com/appwrite/sdk-for-python',
                'enabled' => true,
                'beta' => true,
                'prism' => 'python',
                'source' => realpath(__DIR__ . '/../sdks/python'),
            ],
            [
                'name' => 'Go',
                'repository' => 'https://github.com/appwrite/sdk-for-go',
                'enabled' => true,
                'beta' => true,
                'prism' => 'go',
                'source' => realpath(__DIR__ . '/../sdks/go'),
            ],
            [
                'name' => 'Ruby',
                'repository' => 'https://github.com/appwrite/sdk-for-ruby',
                'enabled' => true,
                'beta' => true,
                'prism' => 'ruby',
                'source' => realpath(__DIR__ . '/../sdks/ruby'),
            ],
        ],
    ],
    
];
