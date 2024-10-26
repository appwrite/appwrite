<?php

/**
 * List of Appwrite Sites supported frameworks
 */

const TEMPLATE_RUNTIMES = [
    'NODE' => [
        'name' => 'node',
        'versions' => ['22', '21.0', '20.0', '19.0', '18.0', '16.0', '14.5']
    ],
    'PYTHON' => [
        'name' => 'python',
        'versions' => ['3.12', '3.11', '3.10', '3.9', '3.8']
    ],
    'DART' => [
        'name' => 'dart',
        'versions' => ['3.5', '3.3', '3.1', '3.0', '2.19', '2.18', '2.17', '2.16', '2.16']
    ],
    'GO' => [
        'name' => 'go',
        'versions' => ['1.23']
    ],
    'PHP' => [
        'name' => 'php',
        'versions' => ['8.3', '8.2', '8.1', '8.0']
    ],
    'DENO' => [
        'name' => 'deno',
        'versions' => ['2.0', '1.46', '1.40', '1.35', '1.24', '1.21']
    ],
    'BUN' => [
        'name' => 'bun',
        'versions' => ['1.1', '1.0']
    ],
    'RUBY' => [
        'name' => 'ruby',
        'versions' => ['3.3', '3.2', '3.1', '3.0']
    ],
];

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
        'serveRuntimes' => getVersions(TEMPLATE_RUNTIMES['NODE']['versions'], 'node'),
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions(TEMPLATE_RUNTIMES['NODE']['versions'], 'node')
    ],
    'nextjs' => [
        'key' => 'nextjs',
        'name' => 'Next.js',
        'logo' => 'nextjs.png',
        'defaultServeRuntime' => 'node-22',
        'serveRuntimes' => getVersions(TEMPLATE_RUNTIMES['NODE']['versions'], 'node'),
        'defaultBuildRuntime' => 'node-22',
        'buildRuntimes' => getVersions(TEMPLATE_RUNTIMES['NODE']['versions'], 'node')
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
        'buildRuntimes' => getVersions(TEMPLATE_RUNTIMES['NODE']['versions'], 'node')
    ]
];
