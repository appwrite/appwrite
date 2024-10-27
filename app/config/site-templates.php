<?php

const TEMPLATE_FRAMEWORKS = [
    'SVELTEKIT' => [
        'name' => 'Svelte Kit'
    ],
    'NEXTJS' => [
        'name' => 'Next.js'
    ],
];

function getFramework($framework, $installCommand, $buildCommand, $outputDirectory, $providerRootDirectory)
{
    return [
        'name' => $framework['name'],
        'installCommand' => $installCommand,
        'buildCommand' => $buildCommand,
        'outputDirectory' => $outputDirectory,
        'providerRootDirectory' => $providerRootDirectory
    ];
}

return [
    [
        'id' => 'starter',
        'name' => 'Personal portfolio',
        'useCases' => ['starter'],
        'frameworks' => [
            ...getFramework(TEMPLATE_FRAMEWORKS['SVELTEKIT'], 'npm install --force', 'npm run build', './build', './')
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'portfolio-walter-o-brien',
        'providerOwner' => 'adityaoberai',
        'providerVersion' => '0.1.*',
        'variables' => [],
    ],
];
