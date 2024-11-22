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
        'key' => 'nextjs-starter',
        'name' => 'Next.js Starter website',
        'useCases' => ['starter'],
        'demoUrl' => 'https://nextjs-starter.sites.qa17.appwrite.org/',
        'demoImage' => 'https://qa17.appwrite.org/console/images/sites/templates/nextjs-starter.png',
        'frameworks' => [
            getFramework('NEXTJS', [
                'providerRootDirectory' => './nextjs/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [],
    ],
    [
        'key' => 'nuxt-starter',
        'name' => 'Nuxt Starter website',
        'useCases' => ['starter'],
        'demoUrl' => 'https://nuxt-starter.sites.qa17.appwrite.org/',
        'demoImage' => 'https://qa17.appwrite.org/console/images/sites/templates/nuxt-starter.png',
        'frameworks' => [
            getFramework('NUXT', [
                'providerRootDirectory' => './nuxt/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [],
    ],
    [
        'key' => 'sveltekit-starter',
        'name' => 'SvelteKit Starter website',
        'useCases' => ['starter'],
        'demoUrl' => 'https://sveltekit-starter.sites.qa17.appwrite.org/',
        'demoImage' => 'https://qa17.appwrite.org/console/images/sites/templates/sveltekit-starter.png',
        'frameworks' => [
            getFramework('SVELTEKIT', [
                'providerRootDirectory' => './sveltekit/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [],
    ],
    [
        'key' => 'astro-starter',
        'name' => 'Astro Starter website',
        'useCases' => ['starter'],
        'demoUrl' => 'https://astro-starter.sites.qa17.appwrite.org/',
        'demoImage' => 'https://qa17.appwrite.org/console/images/sites/templates/astro-starter.png',
        'frameworks' => [
            getFramework('ASTRO', [
                'providerRootDirectory' => './astro/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [],
    ],
    [
        'key' => 'remix-starter',
        'name' => 'Remix Starter website',
        'useCases' => ['starter'],
        'demoUrl' => 'https://remix-starter.sites.qa17.appwrite.org/',
        'demoImage' => 'https://qa17.appwrite.org/console/images/sites/templates/remix-starter.png',
        'frameworks' => [
            getFramework('REMIX', [
                'providerRootDirectory' => './remix/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [],
    ],
    [
        'key' => 'angular-starter',
        'name' => 'Angular Starter website',
        'useCases' => ['starter'],
        'demoUrl' => 'https://angular-starter.sites.qa17.appwrite.org/',
        'demoImage' => 'https://qa17.appwrite.org/console/images/sites/templates/angular-starter.png',
        'frameworks' => [
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
