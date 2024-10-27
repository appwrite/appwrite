<?php

const TEMPLATE_FRAMEWORKS = [
    'SVELTEKIT' => [
        'name' => 'Svelte Kit',
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
        'id' => 'starter',
        'name' => 'Personal portfolio',
        'useCases' => ['starter'],
        'frameworks' => [
            getFramework('SVELTEKIT', [
                'serveRuntime' => 'static-1',
                'installCommand' => 'npm install --force',
                'providerRootDirectory' => './'
            ])
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'portfolio-walter-o-brien',
        'providerOwner' => 'adityaoberai',
        'providerVersion' => '0.1.*',
        'variables' => [],
    ],
];
