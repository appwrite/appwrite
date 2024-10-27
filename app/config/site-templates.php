<?php

const TEMPLATE_FRAMEWORKS = [
    'SVELTEKIT' => [
        'name' => 'sveltekit'
    ],
    'NEXTJS' => [
        'name' => 'nextjs'
    ],
];

function getFramework($framework, $installCommand, $buildCommand, $outputDirectory, $fallbackRedirect, $providerRootDirectory)
{
    return [
        'name' => $framework['name'],
        'installCommand' => $installCommand,
        'buildCommand' => $buildCommand,
        'outputDirectory' => $outputDirectory,
        'fallbackRedirect' => $fallbackRedirect,
        'providerRootDirectory' => $providerRootDirectory
    ];
}

return [
    [
        'icon' => 'icon-lightning-bolt',
        'id' => 'starter',
        'name' => 'Starter site',
        'tagline' =>
        'A simple site to get started. Edit this site to explore endless possibilities with Appwrite Sites.',
        'useCases' => ['starter'],
        'frameworks' => [
            ...getFramework(TEMPLATE_FRAMEWORKS['SVELTEKIT'], 'npm install', 'npm run build', 'build', 'index.html', 'node/starter')
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/starter">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [],
        'scopes' => ['users.read']
    ],
    [
        'icon' => 'icon-lightning-bolt',
        'id' => 'starter1',
        'name' => 'Starter1 site',
        'tagline' =>
        'A simple site to get started. Edit this site to explore endless possibilities with Appwrite Sites.',
        'useCases' => ['messaging'],
        'frameworks' => [
            ...getFramework(TEMPLATE_FRAMEWORKS['SVELTEKIT'], 'npm install', 'npm run build', 'build', 'index.html', 'node/starter1')
        ],
        'instructions' => 'For documentation and instructions check out <a target="_blank" rel="noopener noreferrer" class="link" href="https://github.com/appwrite/templates/tree/main/node/starter">file</a>.',
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [],
        'scopes' => ['users.read']
    ]
];
