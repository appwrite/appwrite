<?php

use Utopia\System\System;

/**
 * List of Appwrite Sites templates
 */

$protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
$hostname = System::getEnv('_APP_DOMAIN');

// TODO: Development override
if (System::getEnv('_APP_ENV') === 'development') {
    $hostname = 'localhost';
}

$url = $protocol . '://' . $hostname;

const TEMPLATE_FRAMEWORKS = [
    'SVELTEKIT' => [
        'key' => 'sveltekit',
        'name' => 'SvelteKit',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'outputDirectory' => './build',
        'buildRuntime' => 'node-22',
        'adapter' => 'ssr',
        'fallbackFile' => '',
    ],
    'NEXTJS' => [
        'key' => 'nextjs',
        'name' => 'Next.js',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'outputDirectory' => './.next',
        'buildRuntime' => 'node-22',
        'adapter' => 'ssr',
        'fallbackFile' => '',
    ],
    'NUXT' => [
        'key' => 'nuxt',
        'name' => 'Nuxt',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'outputDirectory' => './.output',
        'buildRuntime' => 'node-22',
        'adapter' => 'ssr',
        'fallbackFile' => '',
    ],
    'REMIX' => [
        'key' => 'remix',
        'name' => 'Remix',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'outputDirectory' => './build',
        'buildRuntime' => 'node-22',
        'adapter' => 'ssr',
        'fallbackFile' => '',
    ],
    'ASTRO' => [
        'key' => 'astro',
        'name' => 'Astro',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'outputDirectory' => './dist',
        'buildRuntime' => 'node-22',
        'adapter' => 'ssr',
        'fallbackFile' => '',
    ],
    'FLUTTER' => [
        'key' => 'flutter',
        'name' => 'Flutter',
        'installCommand' => '',
        'buildCommand' => 'flutter build web',
        'outputDirectory' => './build/web',
        'buildRuntime' => 'flutter-3.24',
        'adapter' => 'static',
        'fallbackFile' => '',
    ],
    'OTHER' => [
        'key' => 'other',
        'name' => 'Other',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'buildRuntime' => 'node-22',
        'adapter' => 'static',
        'fallbackFile' => 'index.html',
    ],
    'REACT' => [
        'key' => 'react',
        'name' => 'React',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'buildRuntime' => 'node-22',
        'adapter' => 'static',
        'outputDirectory' => './dist',
        'fallbackFile' => 'index.html',
    ],
    'ANGULAR' => [
        'key' => 'angular',
        'name' => 'Angular',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'buildRuntime' => 'node-22',
        'adapter' => 'static',
        'outputDirectory' => './dist/angular/browser',
        'fallbackFile' => 'index.html',
    ],
    'VUE' => [
        'key' => 'vue',
        'name' => 'Vue.js',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'buildRuntime' => 'node-22',
        'adapter' => 'static',
        'outputDirectory' => './dist',
        'fallbackFile' => 'index.html',
    ],
];

function getFramework(string $frameworkEnum, array $overrides)
{
    $settings = \array_merge(TEMPLATE_FRAMEWORKS[$frameworkEnum], $overrides);
    return $settings;
}

return [
    [
        'key' => 'template-for-onelink',
        'name' => 'Onelink template',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/template-for-onelink-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/template-for-onelink-light.png',
        'frameworks' => [
            getFramework('NUXT', [
                'providerRootDirectory' => './onelink',
                'buildCommand' => 'npm run generate',
                'outputDirectory' => './dist',
                'adapter' => 'static',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'Meldiron',
        'providerVersion' => '0.1.*',
        'variables' => []
    ],
    [
        'key' => 'starter-for-js',
        'name' => 'JavaScript starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/starter-for-js-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-js-light.png',
        'frameworks' => [
            getFramework('OTHER', [
                'installCommand' => 'npm install',
                'buildCommand' => 'npm run build',
                'providerRootDirectory' => './',
                'outputDirectory' => './dist',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-js',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [
            [
                'name' => 'VITE_APPWRITE_ENDPOINT',
                'description' => 'Endpoint of Appwrite server',
                'value' => '{apiEndpoint}',
                'placeholder' => '{apiEndpoint}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'VITE_APPWRITE_PROJECT_ID',
                'description' => 'Your Appwrite project ID',
                'value' => '{projectId}',
                'placeholder' => '{projectId}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'VITE_APPWRITE_PROJECT_NAME',
                'description' => 'Your Appwrite project name',
                'value' => '{projectName}',
                'placeholder' => '{projectName}',
                'required' => true,
                'type' => 'text'
            ],
        ]
    ],
    [
        'key' => 'starter-for-angular',
        'name' => 'Angular starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/starter-for-angular-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-angular-light.png',
        'frameworks' => [
            getFramework('ANGULAR', [
                'providerRootDirectory' => './',
                'outputDirectory' => './dist/angular-starter-kit-for-appwrite/browser',
                'buildCommand' => 'sh prepare-env.sh && npm run build'
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-angular',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [
            [
                'name' => 'APPWRITE_ENDPOINT',
                'description' => 'Endpoint of Appwrite server',
                'value' => '{apiEndpoint}',
                'placeholder' => '{apiEndpoint}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_PROJECT_ID',
                'description' => 'Your Appwrite project ID',
                'value' => '{projectId}',
                'placeholder' => '{projectId}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_PROJECT_NAME',
                'description' => 'Your Appwrite project name',
                'value' => '{projectName}',
                'placeholder' => '{projectName}',
                'required' => true,
                'type' => 'text'
            ],
        ]
    ],
    [
        'key' => 'starter-for-svelte',
        'name' => 'Svelte starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/starter-for-svelte-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-svelte-light.png',
        'frameworks' => [
            getFramework('SVELTEKIT', [
                'providerRootDirectory' => './',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-svelte',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [
            [
                'name' => 'PUBLIC_APPWRITE_ENDPOINT',
                'description' => 'Endpoint of Appwrite server',
                'value' => '{apiEndpoint}',
                'placeholder' => '{apiEndpoint}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'PUBLIC_APPWRITE_PROJECT_ID',
                'description' => 'Your Appwrite project ID',
                'value' => '{projectId}',
                'placeholder' => '{projectId}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'PUBLIC_APPWRITE_PROJECT_NAME',
                'description' => 'Your Appwrite project name',
                'value' => '{projectName}',
                'placeholder' => '{projectName}',
                'required' => true,
                'type' => 'text'
            ],
        ]
    ],
    [
        'key' => 'starter-for-react',
        'name' => 'React starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/starter-for-react-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-react-light.png',
        'frameworks' => [
            getFramework('REACT', [
                'providerRootDirectory' => './',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-react',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [
            [
                'name' => 'VITE_APPWRITE_ENDPOINT',
                'description' => 'Endpoint of Appwrite server',
                'value' => '{apiEndpoint}',
                'placeholder' => '{apiEndpoint}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'VITE_APPWRITE_PROJECT_ID',
                'description' => 'Your Appwrite project ID',
                'value' => '{projectId}',
                'placeholder' => '{projectId}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'VITE_APPWRITE_PROJECT_NAME',
                'description' => 'Your Appwrite project name',
                'value' => '{projectName}',
                'placeholder' => '{projectName}',
                'required' => true,
                'type' => 'text'
            ],
        ]
    ],
    [
        'key' => 'starter-for-vue',
        'name' => 'Vue starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/starter-for-vue-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-vue-light.png',
        'frameworks' => [
            getFramework('VUE', [
                'providerRootDirectory' => './',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-vue',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [
            [
                'name' => 'VITE_APPWRITE_ENDPOINT',
                'description' => 'Endpoint of Appwrite server',
                'value' => '{apiEndpoint}',
                'placeholder' => '{apiEndpoint}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'VITE_APPWRITE_PROJECT_ID',
                'description' => 'Your Appwrite project ID',
                'value' => '{projectId}',
                'placeholder' => '{projectId}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'VITE_APPWRITE_PROJECT_NAME',
                'description' => 'Your Appwrite project name',
                'value' => '{projectName}',
                'placeholder' => '{projectName}',
                'required' => true,
                'type' => 'text'
            ],
        ]
    ],
    [
        'key' => 'starter-for-react-native',
        'name' => 'React Native starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/starter-for-react-native-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-react-native-light.png',
        'frameworks' => [
            getFramework('REACT', [
                'providerRootDirectory' => './',
                'fallbackFile' => '+not-found.html',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-react-native',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [
            [
                'name' => 'EXPO_PUBLIC_APPWRITE_ENDPOINT',
                'description' => 'Endpoint of Appwrite server',
                'value' => '{apiEndpoint}',
                'placeholder' => '{apiEndpoint}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'EXPO_PUBLIC_APPWRITE_PROJECT_ID',
                'description' => 'Your Appwrite project ID',
                'value' => '{projectId}',
                'placeholder' => '{projectId}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'EXPO_PUBLIC_APPWRITE_PROJECT_NAME',
                'description' => 'Your Appwrite project name',
                'value' => '{projectName}',
                'placeholder' => '{projectName}',
                'required' => true,
                'type' => 'text'
            ],
        ]
    ],
    [
        'key' => 'starter-for-nextjs',
        'name' => 'Next.js starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/starter-for-nextjs-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-nextjs-light.png',
        'frameworks' => [
            getFramework('NEXTJS', [
                'providerRootDirectory' => './',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-nextjs',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [
            [
                'name' => 'NEXT_PUBLIC_APPWRITE_ENDPOINT',
                'description' => 'Endpoint of Appwrite server',
                'value' => '{apiEndpoint}',
                'placeholder' => '{apiEndpoint}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'NEXT_PUBLIC_APPWRITE_PROJECT_ID',
                'description' => 'Your Appwrite project ID',
                'value' => '{projectId}',
                'placeholder' => '{projectId}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'NEXT_PUBLIC_APPWRITE_PROJECT_NAME',
                'description' => 'Your Appwrite project name',
                'value' => '{projectName}',
                'placeholder' => '{projectName}',
                'required' => true,
                'type' => 'text'
            ],
        ]
    ],
    [
        'key' => 'starter-for-nuxt',
        'name' => 'Nuxt starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/starter-for-nuxt-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-nuxt-light.png',
        'frameworks' => [
            getFramework('NUXT', [
                'providerRootDirectory' => './',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-nuxt',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [
            [
                'name' => 'NUXT_PUBLIC_APPWRITE_ENDPOINT',
                'description' => 'Endpoint of Appwrite server',
                'value' => '{apiEndpoint}',
                'placeholder' => '{apiEndpoint}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'NUXT_PUBLIC_APPWRITE_PROJECT_ID',
                'description' => 'Your Appwrite project ID',
                'value' => '{projectId}',
                'placeholder' => '{projectId}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'NUXT_PUBLIC_APPWRITE_PROJECT_NAME',
                'description' => 'Your Appwrite project name',
                'value' => '{projectName}',
                'placeholder' => '{projectName}',
                'required' => true,
                'type' => 'text'
            ],
        ]
    ],
    [
        'key' => 'template-for-event',
        'name' => 'Event template',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/template-for-event-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/template-for-event-light.png',
        'frameworks' => [
            getFramework('NEXTJS', [
                'providerRootDirectory' => './',
                'installCommand' => 'pnpm install',
                'buildCommand' => 'npm run build',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'template-for-event',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [
            [
                'name' => 'NEXT_PUBLIC_APPWRITE_FUNCTION_PROJECT_ID',
                'description' => 'Endpoint of Appwrite server',
                'value' => '{apiEndpoint}',
                'placeholder' => '{apiEndpoint}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'NEXT_PUBLIC_APPWRITE_FUNCTION_API_ENDPOINT',
                'description' => 'Your Appwrite project ID',
                'value' => '{projectId}',
                'placeholder' => '{projectId}',
                'required' => true,
                'type' => 'text'
            ],
        ]
    ],
    [
        'key' => 'template-for-portfolio',
        'name' => 'Portfolio template',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/template-for-portfolio-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/template-for-portfolio-light.png',
        'frameworks' => [
            getFramework('NEXTJS', [
                'providerRootDirectory' => './',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'template-for-portfolio',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => []
    ],
    [
        'key' => 'template-for-store',
        'name' => 'Store template',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/template-for-store-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/template-for-store-light.png',
        'frameworks' => [
            getFramework('SVELTEKIT', [
                'providerRootDirectory' => './',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'template-for-store',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [
            [
                'name' => 'STRIPE_SECRET_KEY',
                'description' => 'Your Stripe secret key',
                'value' => 'disabled',
                'placeholder' => 'sk_.....',
                'required' => false,
                'type' => 'password'
            ],
            [
                'name' => 'PUBLIC_APPWRITE_ENDPOINT',
                'description' => 'Endpoint of Appwrite server',
                'value' => '{apiEndpoint}',
                'placeholder' => '{apiEndpoint}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'PUBLIC_APPWRITE_PROJECT_ID',
                'description' => 'Your Appwrite project ID',
                'value' => '{projectId}',
                'placeholder' => '{projectId}',
                'required' => true,
                'type' => 'text'
            ],
        ]
    ],
    [
        'key' => 'template-for-blog',
        'name' => 'Blog template',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/template-for-blog-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/template-for-blog-light.png',
        'frameworks' => [
            getFramework('SVELTEKIT', [
                'providerRootDirectory' => './',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'template-for-blog',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => []
    ],
    [
        'key' => 'astro-starter',
        'name' => 'Astro starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/astro-starter-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/astro-starter-light.png',
        'frameworks' => [
            getFramework('ASTRO', [
                'providerRootDirectory' => './astro/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [],
    ],
    [
        'key' => 'remix-starter',
        'name' => 'Remix starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/remix-starter-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/remix-starter-light.png',
        'frameworks' => [
            getFramework('REMIX', [
                'providerRootDirectory' => './remix/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [],
    ],
    [
        'key' => 'flutter-starter',
        'name' => 'Flutter starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/flutter-starter-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/flutter-starter-light.png',
        'frameworks' => [
            getFramework('FLUTTER', [
                'providerRootDirectory' => './flutter/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [],
    ],
    [
        'key' => 'nextjs-starter',
        'name' => 'Next.js starter website',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/nextjs-starter-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/nextjs-starter-light.png',
        'frameworks' => [
            getFramework('NEXTJS', [
                'providerRootDirectory' => './nextjs/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [],
    ],
    [
        'key' => 'nuxt-starter',
        'name' => 'Nuxt starter website',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/nuxt-starter-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/nuxt-starter-light.png',
        'frameworks' => [
            getFramework('NUXT', [
                'providerRootDirectory' => './nuxt/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [],
    ],
    [
        'key' => 'sveltekit-starter',
        'name' => 'SvelteKit starter website',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/sveltekit-starter-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/sveltekit-starter-light.png',
        'frameworks' => [
            getFramework('SVELTEKIT', [
                'providerRootDirectory' => './sveltekit/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.2.*',
        'variables' => [],
    ],
];
