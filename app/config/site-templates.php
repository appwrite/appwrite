<?php

const TEMPLATE_FRAMEWORKS = [
    'SVELTEKIT' => [
        'key' => 'sveltekit',
        'name' => 'SvelteKit',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'outputDirectory' => './build',
        'serveRuntime' => 'node-22',
        'buildRuntime' => 'node-22',
    ],
];

function getFramework(string $frameworkEnum, array $overrides)
{
    $settings = \array_merge(TEMPLATE_FRAMEWORKS[$frameworkEnum], $overrides);
    return $settings;
}

return [
    [
        'key' => 'starter',
        'name' => 'Starter website',
        'useCases' => ['starter'],
        'frameworks' => [
            getFramework('SVELTEKIT', [
                'serveRuntime' => 'static-1',
                'installCommand' => 'npm install',
                'buildCommand' => 'npm run build',
                'providerRootDirectory' => './sveltekit/starter',
                'outputDirectory' => 'build',
                'fallbackFile' => null
            ])
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [],
    ],
];
