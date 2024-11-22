<?php

const TEMPLATE_FRAMEWORKS = [
    'SVELTEKIT' => [
        'key' => 'sveltekit',
        'name' => 'SvelteKit',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'outputDirectory' => './build',
        'buildRuntime' => 'node-22',
        'serveRuntime' => 'static-1',
        'fallbackFile' => null,
    ],
    'NEXTJS' => [
        'key' => 'nextjs',
        'name' => 'Next.js',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'outputDirectory' => './out',
        'buildRuntime' => 'node-22',
        'serveRuntime' => 'static-1',
        'fallbackFile' => null,
    ],
    'NUXT' => [
        'key' => 'nuxt',
        'name' => 'Nuxt',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run generate',
        'outputDirectory' => './dist',
        'buildRuntime' => 'node-22',
        'serveRuntime' => 'static-1',
        'fallbackFile' => null,
    ],
    'REMIX' => [
        'key' => 'remix',
        'name' => 'Remix',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'outputDirectory' => './build/client',
        'buildRuntime' => 'node-22',
        'serveRuntime' => 'static-1',
        'fallbackFile' => null,
    ],
    'ASTRO' => [
        'key' => 'astro',
        'name' => 'Astro',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'outputDirectory' => './dist',
        'buildRuntime' => 'node-22',
        'serveRuntime' => 'static-1',
        'fallbackFile' => null,
    ],
    'ANGULAR' => [
        'key' => 'angular',
        'name' => 'Angular',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'outputDirectory' => './dist/starter/browser',
        'buildRuntime' => 'node-22',
        'serveRuntime' => 'static-1',
        'fallbackFile' => null,
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
            getFramework('NEXTJS', [
                'providerRootDirectory' => './nextjs/starter',
            ]),
            getFramework('NUXT', [
                'providerRootDirectory' => './nuxt/starter',
            ]),
            getFramework('SVELTEKIT', [
                'providerRootDirectory' => './sveltekit/starter',
            ]),
            getFramework('ASTRO', [
                'providerRootDirectory' => './astro/starter',
            ]),
            getFramework('REMIX', [
                'providerRootDirectory' => './remix/starter',
            ]),
            getFramework('ANGULAR', [
                'providerRootDirectory' => './angular/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [],
    ],
];
