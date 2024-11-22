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
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node')
    ],
    'nextjs' => [
        'key' => 'nextjs',
        'name' => 'Next.js',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node')
    ],
    'nuxt' => [
        'key' => 'nuxt',
        'name' => 'Nuxt',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node')
    ],
    'angular' => [
        'key' => 'angular',
        'name' => 'Angular',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node')
    ],
    'astro' => [
        'key' => 'astro',
        'name' => 'Astro',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node')
    ],
    'remix' => [
        'key' => 'remix',
        'name' => 'Remix',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node')
    ],
    'static' => [
        'key' => 'static',
        'name' => 'Static',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node')
    ]
];
