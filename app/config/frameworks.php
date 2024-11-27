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
    'sveltekit' => [
        'key' => 'sveltekit',
        'name' => 'SvelteKit',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'defaultBuildCommand' => 'npm run build',
        'defaultInstallCommand' => 'npm install',
        'defaultOutputDirectory' => './build',
        'startCommand' => 'cd src/function && node index.js',
        'bundleCommand' => 'cp package*.json build/ && cp -R node_modules/ build/node_modules/',
    ],
    'nextjs' => [
        'key' => 'nextjs',
        'name' => 'Next.js',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'defaultBuildCommand' => 'npm run build',
        'defaultInstallCommand' => 'npm install',
        'defaultOutputDirectory' => './out',
    ],
    'nuxt' => [
        'key' => 'nuxt',
        'name' => 'Nuxt',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'defaultBuildCommand' => 'npm run generate',
        'defaultInstallCommand' => 'npm install',
        'defaultOutputDirectory' => './dist',
    ],
    /*
    'angular' => [
        'key' => 'angular',
        'name' => 'Angular',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'defaultBuildCommand' => 'npm run build',
        'defaultInstallCommand' => 'npm install',
        'defaultOutputDirectory' => './dist/starter/browser',
    ],
    */
    'astro' => [
        'key' => 'astro',
        'name' => 'Astro',
        'defaultServeRuntime' => 'node-22',
        'serveRuntimes' => [
            ...getVersions($templateRuntimes['NODE']['versions'], 'node'),
            'static-1',
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'defaultBuildCommand' => 'npm run build',
        'defaultInstallCommand' => 'npm install',
        'defaultOutputDirectory' => './dist',
        // 'startCommand' => 'sh helpers/server-astro.sh',
        // 'bundleCommand' => 'sh helpers/bundle-astro.sh',
        'startCommand' => 'cd src/function && HOST=0.0.0.0 PORT=3000 node server/entry.mjs',
        'bundleCommand' => 'cp package*.json dist/server/ && cp -R node_modules/ dist/server/node_modules/',
    ],
    'remix' => [
        'key' => 'remix',
        'name' => 'Remix',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'defaultBuildCommand' => 'npm run build',
        'defaultInstallCommand' => 'npm install',
        'defaultOutputDirectory' => './build/client',
    ],
    'flutter' => [
        'key' => 'flutter',
        'name' => 'Flutter',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'flutter-3.24',
        'buildRuntimes' => getVersions($templateRuntimes['FLUTTER']['versions'], 'flutter'),
        'defaultBuildCommand' => 'flutter build web',
        'defaultInstallCommand' => '',
        'defaultOutputDirectory' => './build/web',
    ],
    'static' => [
        'key' => 'static',
        'name' => 'Static',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'defaultBuildCommand' => 'npm run build',
        'defaultInstallCommand' => 'npm install',
        'defaultOutputDirectory' => './build',
    ]
];
