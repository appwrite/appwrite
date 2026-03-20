<?php

/**
 * List of Appwrite Sites supported frameworks
 */

use Utopia\Config\Config;

$templateRuntimes = Config::getParam('template-runtimes');

return [
    'analog' => [
        'key' => 'analog',
        'name' => 'Analog',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'bundleCommand' => 'bash /usr/local/server/helpers/analog/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/analog/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist/analog',
                'startCommand' => 'bash helpers/analog/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist/analog/public',
                'startCommand' => 'bash helpers/server.sh',
                'fallbackFile' => 'index.html'
            ]
        ]
    ],
    'angular' => [
        'key' => 'angular',
        'name' => 'Angular',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'bundleCommand' => 'bash /usr/local/server/helpers/angular/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/angular/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist/angular',
                'startCommand' => 'bash helpers/angular/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist/angular/browser',
                'startCommand' => 'bash helpers/server.sh',
                'fallbackFile' => 'index.csr.html'
            ]
        ]
    ],
    'nextjs' => [
        'key' => 'nextjs',
        'name' => 'Next.js',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'bundleCommand' => 'bash /usr/local/server/helpers/next-js/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/next-js/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './.next',
                'startCommand' => 'bash helpers/next-js/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './out',
                'startCommand' => 'bash helpers/server.sh',
            ]
        ]
    ],
    'react' => [
        'key' => 'react',
        'name' => 'React',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'bash helpers/server.sh',
                'fallbackFile' => 'index.html'
            ]
        ]
    ],
    'nuxt' => [
        'key' => 'nuxt',
        'name' => 'Nuxt',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'bundleCommand' => 'bash /usr/local/server/helpers/nuxt/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/nuxt/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './.output',
                'startCommand' => 'bash helpers/nuxt/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run generate',
                'installCommand' => 'npm install',
                'outputDirectory' => './output/public',
                'startCommand' => 'bash helpers/server.sh',
            ]
        ]
    ],
    'vue' => [
        'key' => 'vue',
        'name' => 'Vue.js',
        'screenshotSleep' => 5000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'bash helpers/server.sh',
                'fallbackFile' => 'index.html'
            ]
        ]
    ],
    'sveltekit' => [
        'key' => 'sveltekit',
        'name' => 'SvelteKit',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'bundleCommand' => 'bash /usr/local/server/helpers/sveltekit/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/sveltekit/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './build',
                'startCommand' => 'bash helpers/sveltekit/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './build',
                'startCommand' => 'bash helpers/server.sh',
            ]
        ]
    ],
    'astro' => [
        'key' => 'astro',
        'name' => 'Astro',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'bundleCommand' => 'bash /usr/local/server/helpers/astro/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/astro/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'bash helpers/astro/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'bash helpers/server.sh',
            ]
        ]
    ],
    'tanstack-start' => [
        'key' => 'tanstack-start',
        'name' => 'TanStack Start',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'bundleCommand' => 'bash /usr/local/server/helpers/tanstack-start/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/tanstack-start/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './.output',
                'startCommand' => 'bash helpers/tanstack-start/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist/client',
                'startCommand' => 'bash helpers/server.sh',
            ]
        ]
    ],
    'remix' => [
        'key' => 'remix',
        'name' => 'Remix',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'bundleCommand' => 'bash /usr/local/server/helpers/remix/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/remix/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './build',
                'startCommand' => 'bash helpers/remix/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './build/client',
                'startCommand' => 'bash helpers/server.sh',
            ]
        ]
    ],
    'lynx' => [
        'key' => 'lynx',
        'name' => 'Lynx',
        'screenshotSleep' => 5000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'bash helpers/server.sh',
                'fallbackFile' => 'index.html'
            ]
        ]
    ],
    'flutter' => [
        'key' => 'flutter',
        'name' => 'Flutter',
        'screenshotSleep' => 5000,
        'buildRuntime' => 'flutter-3.35',
        'runtimes' => $templateRuntimes['FLUTTER'],
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => 'flutter build web --release -t lib/main.dart',
                'installCommand' => 'flutter pub get',
                'outputDirectory' => './build/web',
                'startCommand' => 'bash helpers/server.sh',
                'fallbackFile' => 'index.html'
            ],
        ],
    ],
    'react-native' => [
        'key' => 'react-native',
        'name' => 'React Native',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'bash helpers/server.sh',
                'fallbackFile' => 'index.html'
            ]
        ]
    ],
    'vite' => [
        'key' => 'vite',
        'name' => 'Vite',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'bash helpers/server.sh',
            ],
        ]
    ],
    'other' => [
        'key' => 'other',
        'name' => 'Other',
        'screenshotSleep' => 3000,
        'buildRuntime' => 'node-22',
        'runtimes' => $templateRuntimes['NODE'],
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => '',
                'installCommand' => '',
                'outputDirectory' => './',
                'startCommand' => 'bash helpers/server.sh',
            ],
        ]
    ],
];
