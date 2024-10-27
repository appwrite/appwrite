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
        'logo' => 'sveltekit.png',
        'defaultServeRuntime' => 'node-22',
        'serveRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node')
    ],
    'nextjs' => [
        'key' => 'nextjs',
        'name' => 'Next.js',
        'logo' => 'nextjs.png',
        'defaultServeRuntime' => 'node-22',
        'serveRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node'),
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node')
    ],
    'static' => [
        'key' => 'static',
        'name' => 'Static',
        'logo' => 'static.png',
        'defaultServeRuntime' => 'static-1',
        'serveRuntimes' => [
            'static-1'
        ],
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions($templateRuntimes['NODE']['versions'], 'node')
    ]
];
