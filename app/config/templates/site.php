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
        'buildRuntime' => 'flutter-3.29',
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
    'VITE' => [
        'key' => 'vite',
        'name' => 'Vite',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'buildRuntime' => 'node-22',
        'adapter' => 'static',
        'outputDirectory' => './dist',
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
    'OTHER' => [
        'key' => 'other',
        'name' => 'Other',
        'installCommand' => '',
        'buildCommand' => '',
        'buildRuntime' => 'node-22',
        'adapter' => 'static',
        'outputDirectory' => './',
    ],
];

function getFramework(string $frameworkEnum, array $overrides)
{
    $settings = \array_merge(TEMPLATE_FRAMEWORKS[$frameworkEnum], $overrides);
    return $settings;
}

return [
    [
        'key' => 'nxt-lnk',
        'name' => 'Nxt Lnk',
        'useCases' => ['portfolio'],
        'screenshotDark' => $url . '/images/sites/templates/nxt-lnk-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/nxt-lnk-light.png',
        'frameworks' => [
            getFramework('NEXTJS', [
                'providerRootDirectory' => './nextjs/nxtlnk',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],

    [
        'key' => 'magic-portfolio',
        'name' => 'Magic Portfolio',
        'useCases' => ['portfolio'],
        'screenshotDark' => $url . '/images/sites/templates/magic-portfolio-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/magic-portfolio-light.png',
        'frameworks' => [
            getFramework('NEXTJS', [
                'providerRootDirectory' => './nextjs/magic-portfolio',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],

    [
        'key' => 'littlelink',
        'name' => 'LittleLink',
        'useCases' => ['portfolio'],
        'screenshotDark' => $url . '/images/sites/templates/littlelink-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/littlelink-light.png',
        'frameworks' => [
            getFramework('OTHER', [
                'providerRootDirectory' => './other/littlelink',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],

    [
        'key' => 'logspot',
        'name' => 'Logspot',
        'useCases' => ['blog'],
        'screenshotDark' => $url . '/images/sites/templates/logspot-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/logspot-light.png',
        'frameworks' => [
            getFramework('NUXT', [
                'providerRootDirectory' => './nuxt/logspot',
                'buildCommand' => 'npm run generate',
                'outputDirectory' => './dist',
                'adapter' => 'static',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],
    [
        'key' => 'astro-nano',
        'name' => 'Astro Nano',
        'useCases' => ['portfolio'],
        'screenshotDark' => $url . '/images/sites/templates/astro-nano-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/astro-nano-light.png',
        'frameworks' => [
            getFramework('ASTRO', [
                'providerRootDirectory' => './astro/nano',
                'outputDirectory' => './dist',
                'adapter' => 'static',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],
    [
        'key' => 'astro-starlight',
        'name' => 'Astro Starlight',
        'useCases' => ['documentation'],
        'screenshotDark' => $url . '/images/sites/templates/astro-starlight-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/astro-starlight-light.png',
        'frameworks' => [
            getFramework('ASTRO', [
                'providerRootDirectory' => './astro/starlight',
                'outputDirectory' => './dist',
                'adapter' => 'static',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],
    [
        'key' => 'astro-sphere',
        'name' => 'Astro Sphere',
        'useCases' => ['portfolio'],
        'screenshotDark' => $url . '/images/sites/templates/astro-sphere-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/astro-sphere-light.png',
        'frameworks' => [
            getFramework('ASTRO', [
                'providerRootDirectory' => './astro/sphere',
                'outputDirectory' => './dist',
                'adapter' => 'static',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],
    [
        'key' => 'astro-starlog',
        'name' => 'Astro Starlog',
        'useCases' => ['blog'],
        'screenshotDark' => $url . '/images/sites/templates/astro-starlog-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/astro-starlog-light.png',
        'frameworks' => [
            getFramework('ASTRO', [
                'providerRootDirectory' => './astro/starlog',
                'outputDirectory' => './dist',
                'adapter' => 'static',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],
    [
        'key' => 'onelink',
        'name' => 'Onelink',
        'useCases' => ['portfolio'],
        'screenshotDark' => $url . '/images/sites/templates/onelink-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/onelink-light.png',
        'frameworks' => [
            getFramework('NUXT', [
                'providerRootDirectory' => './nuxt/onelink',
                'buildCommand' => 'npm run generate',
                'outputDirectory' => './dist',
                'adapter' => 'static',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],
    [
        'key' => 'starter-for-flutter',
        'name' => 'Flutter starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/starter-for-flutter-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-flutter-light.png',
        'frameworks' => [
            getFramework('FLUTTER', [
                'providerRootDirectory' => './',
                'buildCommand' => 'bash build.sh',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-flutter',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => [
            [
                'name' => 'APPWRITE_PUBLIC_ENDPOINT',
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
        'key' => 'starter-for-js',
        'name' => 'JavaScript starter',
        'useCases' => ['starter'],
        'screenshotDark' => $url . '/images/sites/templates/starter-for-js-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-js-light.png',
        'frameworks' => [
            getFramework('VITE', [
                'providerRootDirectory' => './',
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
                'buildCommand' => 'bash prepare-env.sh && npm run build'
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
        'useCases' => ['events'],
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
        'useCases' => ['portfolio'],
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
        'useCases' => ['ecommerce'],
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
        'useCases' => ['blog'],
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
];
