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

class UseCases
{
    public const PORTFOLIO = 'portfolio';
    public const STARTER = 'starter';
    public const EVENTS = 'events';
    public const ECOMMERCE = 'ecommerce';
    public const DOCUMENTATION = 'documentation';
    public const BLOG = 'blog';
}

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
    'REACT_NATIVE' => [
        'key' => 'react-native',
        'name' => 'React Native',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'buildRuntime' => 'node-22',
        'adapter' => 'static',
        'outputDirectory' => './dist',
        'fallbackFile' => '+not-found.html',
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
    'ANALOG' => [
        'key' => 'analog',
        'name' => 'Analog',
        'installCommand' => 'npm install',
        'buildCommand' => 'npm run build',
        'buildRuntime' => 'node-22',
        'adapter' => 'ssr',
        'outputDirectory' => './dist/analog',
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
    'LYNX' => [
        'key' => 'lynx',
        'name' => 'Lynx',
        'installCommand' => 'npm install && cd web && npm install && cd ..',
        'buildCommand' => 'npm run build && cd web && npm run build && cd ..',
        'buildRuntime' => 'node-22',
        'adapter' => 'static',
        'outputDirectory' => './web/dist',
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
        'key' => 'template-for-documentation',
        'name' => 'Documentation template',
        'tagline' => 'Modern site to store your knowledge with a clean design, full-text search, dark mode, and more.',
        'score' => 6, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::DOCUMENTATION],
        'screenshotDark' => $url . '/images/sites/templates/template-for-documentation-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/template-for-documentation-light.png',
        'frameworks' => [
            getFramework('ASTRO', [
                'providerRootDirectory' => './',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'template-for-documentation',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.1.*',
        'variables' => []
    ],
    [
        'key' => 'playground-for-lynx',
        'name' => 'Lynx playground',
        'tagline' => 'A basic Lynx website without Appwrite SDK integration.',
        // When we add Lynx with Appwrite SDK, use following tagline for it:
        // 'tagline' => 'Sample application built with Lynx, a cross-platform framework focused on performance.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-lynx-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-lynx-light.png',
        'frameworks' => [
            getFramework('LYNX', [
                'providerRootDirectory' => './lynx/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],
    [
        'key' => 'vitepress',
        'name' => 'Vitepress',
        'tagline' => 'Platform for documentation and knowledge sharing powered by Vite.',
        'score' => 6, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::DOCUMENTATION],
        'screenshotDark' => $url . '/images/sites/templates/vitepress-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/vitepress-light.png',
        'frameworks' => [
            getFramework('VITE', [
                'providerRootDirectory' => './vite/vitepress',
                'outputDirectory' => '404.html',
                'installCommand' => 'npm i vitepress && npm install',
                'buildCommand' => 'npm run docs:build',
                'outputDirectory' => './.vitepress/dist',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],
    [
        'key' => 'vuepress',
        'name' => 'Vuepress',
        'tagline' => 'Platform for documentation and knowledge sharing powered by Vue.',
        'score' => 4, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::DOCUMENTATION],
        'screenshotDark' => $url . '/images/sites/templates/vuepress-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/vuepress-light.png',
        'frameworks' => [
            getFramework('VUE', [
                'providerRootDirectory' => './vue/vuepress',
                'outputDirectory' => '404.html',
                'installCommand' => 'npm install',
                'buildCommand' => 'npm run build',
                'outputDirectory' => './src/.vuepress/dist',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],
    [
        'key' => 'docusaurus',
        'name' => 'Docusaurus',
        'tagline' => 'Platform for documentation and knowledge sharing powered by React.',
        'score' => 4, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::DOCUMENTATION],
        'screenshotDark' => $url . '/images/sites/templates/docusaurus-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/docusaurus-light.png',
        'frameworks' => [
            getFramework('REACT', [
                'providerRootDirectory' => './react/docusaurus',
                'outputDirectory' => '404.html',
                'installCommand' => 'npm install',
                'buildCommand' => 'npm run build',
                'outputDirectory' => './build',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],
    [
        'key' => 'nxt-lnk',
        'name' => 'Nxt Lnk',
        'tagline' => 'Personal website for creators to merge all URLs to social profiles.',
        'score' => 6, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::PORTFOLIO],
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
        'tagline' => 'Complex personal website to showcase your projects, articles, and more.',
        'score' => 7, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::PORTFOLIO],
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
        'tagline' => 'Personal website for creators to merge all URLs to social profiles.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::PORTFOLIO],
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
        'tagline' => 'Website to publish changelogs of your application.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::BLOG],
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
        'tagline' => 'Minimal personal website to showcase your projects, articles, and more.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::PORTFOLIO],
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
        'tagline' => 'Platform for documentation and knowledge sharing powered by Astro.',
        'score' => 6, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::DOCUMENTATION],
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
        'tagline' => 'Modern personal website to showcase your projects, articles, and more.',
        'score' => 7, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::PORTFOLIO],
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
        'tagline' => 'Platform for publishing written content and media powered by Astro.',
        'score' => 5, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::BLOG],
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
        'tagline' => 'Personal website for creators to merge all URLs to social profiles.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::PORTFOLIO],
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
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple Flutter application integrated with Appwrite SDK.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
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
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple JavaScript application integrated with Appwrite SDK.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
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
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple Angular application integrated with Appwrite SDK.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
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
        'key' => 'starter-for-astro',
        'name' => 'Astro starter',
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple Astro application integrated with Appwrite SDK.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'screenshotDark' => $url . '/images/sites/templates/starter-for-astro-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-astro-light.png',
        'frameworks' => [
            getFramework('ASTRO', [
                'providerRootDirectory' => './',
                'adapter' => 'static',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-astro',
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
        'key' => 'starter-for-analog',
        'name' => 'Analog starter',
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple Analog application integrated with Appwrite SDK.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'screenshotDark' => $url . '/images/sites/templates/starter-for-analog-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-analog-light.png',
        'frameworks' => [
            getFramework('ANALOG', [
                'providerRootDirectory' => './',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-analog',
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
        'key' => 'starter-for-remix',
        'name' => 'Remix starter',
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple Remix application integrated with Appwrite SDK.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'screenshotDark' => $url . '/images/sites/templates/starter-for-remix-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-remix-light.png',
        'frameworks' => [
            getFramework('REMIX', [
                'providerRootDirectory' => './',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'starter-for-remix',
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
        'key' => 'starter-for-svelte',
        'name' => 'Svelte starter',
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple Svelte application integrated with Appwrite SDK.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
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
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple React application integrated with Appwrite SDK.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
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
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple Vue application integrated with Appwrite SDK.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
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
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple React Native application integrated with Appwrite SDK.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'screenshotDark' => $url . '/images/sites/templates/starter-for-react-native-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/starter-for-react-native-light.png',
        'frameworks' => [
            getFramework('REACT_NATIVE', [
                'providerRootDirectory' => './',
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
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple Next.js application integrated with Appwrite SDK.',
        'score' => 6, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
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
        'useCases' => [UseCases::STARTER],
        'tagline' => 'Simple Nuxt application integrated with Appwrite SDK.',
        'score' => 3, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
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
        'tagline' => 'Hackathon landing page with support for project submissions.',
        'score' => 6, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::EVENTS],
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
        'tagline' => 'Simple personal website to showcase your projects, articles, and more.',
        'score' => 6, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::PORTFOLIO],
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
        'tagline' => 'E-commerce platform for selling products with Stripe integration.',
        'score' => 7, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::ECOMMERCE],
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
        'tagline' => 'Platform for publishing written content and media.',
        'score' => 7, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::BLOG],
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
        'key' => 'playground-for-astro',
        'name' => 'Astro playground',
        'tagline' => 'A basic Astro website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-astro-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-astro-light.png',
        'frameworks' => [
            getFramework('ASTRO', [
                'providerRootDirectory' => './astro/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],
    [
        'key' => 'playground-for-remix',
        'name' => 'Remix playground',
        'tagline' => 'A basic Remix website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-remix-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-remix-light.png',
        'frameworks' => [
            getFramework('REMIX', [
                'providerRootDirectory' => './remix/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],
    [
        'key' => 'playground-for-nextjs',
        'name' => 'Next.js playground',
        'tagline' => 'A basic Next.js website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-nextjs-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-nextjs-light.png',
        'frameworks' => [
            getFramework('NEXTJS', [
                'providerRootDirectory' => './nextjs/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],
    [
        'key' => 'playground-for-flutter',
        'name' => 'Flutter playground',
        'tagline' => 'A basic Flutter website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-flutter-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-flutter-light.png',
        'frameworks' => [
            getFramework('FLUTTER', [
                'providerRootDirectory' => './flutter/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],
    [
        'key' => 'playground-for-vite',
        'name' => 'Vite playground',
        'tagline' => 'A basic Vite website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-vite-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-vite-light.png',
        'frameworks' => [
            getFramework('VITE', [
                'providerRootDirectory' => './vite/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],
    [
        'key' => 'playground-for-angular',
        'name' => 'Angular playground',
        'tagline' => 'A basic Angular website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-angular-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-angular-light.png',
        'frameworks' => [
            getFramework('ANGULAR', [
                'providerRootDirectory' => './angular/starter',
                'outputDirectory' => './dist/starter/browser',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],
    [
        'key' => 'playground-for-analog',
        'name' => 'Analog playground',
        'tagline' => 'A basic Analog website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-analog-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-analog-light.png',
        'frameworks' => [
            getFramework('ANALOG', [
                'providerRootDirectory' => './analog/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],
    [
        'key' => 'playground-for-svelte',
        'name' => 'Svelte playground',
        'tagline' => 'A basic Svelte website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-svelte-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-svelte-light.png',
        'frameworks' => [
            getFramework('SVELTEKIT', [
                'providerRootDirectory' => './sveltekit/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],

    [
        'key' => 'playground-for-react',
        'name' => 'React playground',
        'tagline' => 'A basic React website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-react-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-react-light.png',
        'frameworks' => [
            getFramework('REACT', [
                'outputDirectory' => './build',
                'providerRootDirectory' => './react/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],

    [
        'key' => 'playground-for-vue',
        'name' => 'Vue playground',
        'tagline' => 'A basic Vue website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-vue-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-vue-light.png',
        'frameworks' => [
            getFramework('VUE', [
                'providerRootDirectory' => './vue/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],
    [
        'key' => 'playground-for-nuxt',
        'name' => 'Nuxt playground',
        'tagline' => 'A basic Nuxt website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-nuxt-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-nuxt-light.png',
        'frameworks' => [
            getFramework('NUXT', [
                'providerRootDirectory' => './nuxt/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],
    [
        'key' => 'playground-for-react-native',
        'name' => 'React Native playground',
        'tagline' => 'A basic React Native website without Appwrite SDK integration.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/playground-for-react-native-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/playground-for-react-native-light.png',
        'frameworks' => [
            getFramework('REACT_NATIVE', [
                'providerRootDirectory' => './react-native/starter',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => [],
    ],
    [
        'key' => 'gallery-for-lynx',
        'name' => 'Lynx gallery',
        'tagline' => 'A Lynx website showcasing gallery with smooth animations.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],
        'screenshotDark' => $url . '/images/sites/templates/gallery-for-lynx-dark.png',
        'screenshotLight' => $url . '/images/sites/templates/gallery-for-lynx-light.png',
        'frameworks' => [
            getFramework('LYNX', [
                'providerRootDirectory' => './lynx/gallery',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.3.*',
        'variables' => []
    ],
    [
        'key' => 'template-for-react-admin-dashboard',
        'name' => 'React Admin Dashboard',
        'tagline' => 'A React-based admin dashboard template with order management features.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER], // TODO: Check if this needs to have a new category
        'screenshotDark' => $url . '/images/sites/templates/gallery-for-lynx-dark.png', // TODO: Update this
        'screenshotLight' => $url . '/images/sites/templates/gallery-for-lynx-light.png', // TODO: Update this
        'frameworks' => [
            getFramework('REACT', [
                'providerRootDirectory' => './react/react-admin',
                'outputDirectory' => './dist',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.4.*',
        'variables' => [
            [
                'name' => 'APPWRITE_SITE_API_ENDPOINT',  // TODO: Update this
                'description' => 'Endpoint of Appwrite server',
                'value' => '{apiEndpoint}',
                'placeholder' => '{apiEndpoint}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'APPWRITE_SITE_PROJECT_ID', // TODO: Update this
                'description' => 'Your Appwrite project ID',
                'value' => '{projectId}',
                'placeholder' => '{projectId}',
                'required' => true,
                'type' => 'text'
            ],
            [
                'name' => 'VITE_APPWRITE_PROJECT_NAME', // TODO: Update this
                'description' => 'Your Appwrite project name',
                'value' => '{projectName}',
                'placeholder' => '{projectName}',
                'required' => true,
                'type' => 'text'
            ],
        ]
    ],
    [
        'key' => 'template-for-formspree',
        'name' => 'Formspree template',
        'tagline' => 'A simple form submission template using Formspree.',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],// TODO: Check if this needs to have a new category
        'screenshotDark' => $url . '/images/sites/templates/gallery-for-lynx-dark.png', // TODO: Update this
        'screenshotLight' => $url . '/images/sites/templates/gallery-for-lynx-light.png', // TODO: Update this
        'frameworks' => [
            getFramework('REACT', [
                'providerRootDirectory' => './react/formspree',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.4.*',
        'variables' => [] // TODO: Add env vars once added to template
    ],
    [
        'key' => 'template-for-clerk',
        'name' => 'Clerk template',
        'tagline' => 'A simple auth and user-management template using Clerk',
        'score' => 1, // 0 to 10 based on looks of screenshot (avoid 1,2,3,8,9,10 if possible)
        'useCases' => [UseCases::STARTER],// TODO: Check if this needs to have a new category
        'screenshotDark' => $url . '/images/sites/templates/gallery-for-lynx-dark.png', // TODO: Update this
        'screenshotLight' => $url . '/images/sites/templates/gallery-for-lynx-light.png', // TODO: Update this
        'frameworks' => [
            getFramework('NEXTJS', [
                'providerRootDirectory' => './nextjs/clerk',
            ]),
        ],
        'vcsProvider' => 'github',
        'providerRepositoryId' => 'templates-for-sites',
        'providerOwner' => 'appwrite',
        'providerVersion' => '0.4.*',
        'variables' => [] // TODO: Add the relevant variables for Clerk
    ]
];
