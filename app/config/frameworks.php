<?php

/**
 * List of Appwrite Sites supported frameworks
 */

use Utopia\Config\Config;

$templateRuntimes = Config::getParam('template-runtimes');

function getVersions(array $versions, string $prefix)
{
    return array_map(function ($version) use ($prefix) {
        return $prefix . '-' . $version;
    }, $versions);
}

return [
    'analog' => [
        'key' => 'analog',
        'name' => 'Analog',
        'buildRuntime' => 'node-22',
        'runtimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'bundleCommand' => 'sh /usr/local/server/helpers/analog/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/analog/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist/analog',
                'startCommand' => 'sh helpers/analog/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist/analog/public',
                'startCommand' => 'sh helpers/server.sh',
                'fallbackFile' => 'index.html'
            ]
        ]
    ],
    'angular' => [
        'key' => 'angular',
        'name' => 'Angular',
        'buildRuntime' => 'node-22',
        'runtimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'bundleCommand' => 'sh /usr/local/server/helpers/angular/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/angular/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist/angular',
                'startCommand' => 'sh helpers/angular/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist/angular/browser',
                'startCommand' => 'sh helpers/server.sh',
                'fallbackFile' => 'index.csr.html'
            ]
        ]
    ],
    'nextjs' => [
        'key' => 'nextjs',
        'name' => 'Next.js',
        'buildRuntime' => 'node-22',
        'runtimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'bundleCommand' => 'sh /usr/local/server/helpers/next-js/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/next-js/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './.next',
                'startCommand' => 'sh helpers/next-js/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './out',
                'startCommand' => 'sh helpers/server.sh',
            ]
        ]
    ],
    'react' => [
        'key' => 'react',
        'name' => 'React',
        'buildRuntime' => 'node-22',
        'runtimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'sh helpers/server.sh',
                'fallbackFile' => 'index.html'
            ]
        ]
    ],
    'nuxt' => [
        'key' => 'nuxt',
        'name' => 'Nuxt',
        'buildRuntime' => 'node-22',
        'runtimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'bundleCommand' => 'sh /usr/local/server/helpers/nuxt/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/nuxt/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './.output',
                'startCommand' => 'sh helpers/nuxt/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run generate',
                'installCommand' => 'npm install',
                'outputDirectory' => './output/public',
                'startCommand' => 'sh helpers/server.sh',
            ]
        ]
    ],
    'vue' => [
        'key' => 'vue',
        'name' => 'Vue.js',
        'buildRuntime' => 'node-22',
        'runtimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'sh helpers/server.sh',
                'fallbackFile' => 'index.html'
            ]
        ]
    ],
    'sveltekit' => [
        'key' => 'sveltekit',
        'name' => 'SvelteKit',
        'buildRuntime' => 'node-22',
        'runtimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'bundleCommand' => 'sh /usr/local/server/helpers/sveltekit/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/sveltekit/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './build',
                'startCommand' => 'sh helpers/sveltekit/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './build',
                'startCommand' => 'sh helpers/server.sh',
            ]
        ]
    ],
    'astro' => [
        'key' => 'astro',
        'name' => 'Astro',
        'buildRuntime' => 'node-22',
        'runtimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'bundleCommand' => 'sh /usr/local/server/helpers/astro/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/astro/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'sh helpers/astro/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'sh helpers/server.sh',
            ]
        ]
    ],
    'remix' => [
        'key' => 'remix',
        'name' => 'Remix',
        'buildRuntime' => 'node-22',
        'runtimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'bundleCommand' => 'sh /usr/local/server/helpers/remix/bundle.sh',
        'envCommand' => 'source /usr/local/server/helpers/remix/env.sh',
        'adapters' => [
            'ssr' => [
                'key' => 'ssr',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './build',
                'startCommand' => 'sh helpers/remix/server.sh',
            ],
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './build/client',
                'startCommand' => 'sh helpers/server.sh',
            ]
        ]
    ],
    'flutter' => [
        'key' => 'flutter',
        'name' => 'Flutter',
        'buildRuntime' => 'flutter-3.29',
        'runtimes' => getVersions($templateRuntimes['FLUTTER']['versions'], 'flutter'),
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => 'flutter build web',
                'installCommand' => '',
                'outputDirectory' => './build/web',
                'startCommand' => 'sh helpers/server.sh',
            ],
        ],
    ],
    'vite' => [
        'key' => 'vite',
        'name' => 'Vite',
        'buildRuntime' => 'node-22',
        'runtimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => 'npm run build',
                'installCommand' => 'npm install',
                'outputDirectory' => './dist',
                'startCommand' => 'sh helpers/server.sh',
            ],
        ]
    ],
    'other' => [
        'key' => 'other',
        'name' => 'Other',
        'buildRuntime' => 'node-22',
        'runtimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'adapters' => [
            'static' => [
                'key' => 'static',
                'buildCommand' => '',
                'installCommand' => '',
                'outputDirectory' => './',
                'startCommand' => 'sh helpers/server.sh',
            ],
        ]
    ],
];
