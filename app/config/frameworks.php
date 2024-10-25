<?php

/**
 * List of Appwrite Sites supported frameworks
 */

return [
    [
        '$id' => 'sveltekit',
        'key' => 'sveltekit',
        'name' => 'SvelteKit',
        'logo' => 'sveltekit.png',
        'defaultRuntime' => 'node-20.0',
        'runtimes' => [
            'node-16.0',
            'node-18.0',
            'node-20.0'
        ],
    ],
    [
        '$id' => 'nextjs',
        'key' => 'nextjs',
        'name' => 'Next.js',
        'logo' => 'nextjs.png',
        'defaultRuntime' => 'node-20.0',
        'runtimes' => [
            'node-16.0',
            'node-18.0',
            'node-20.0'
        ],
    ]
];
