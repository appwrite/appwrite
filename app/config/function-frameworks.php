<?php

/**
 * Web frameworks Appwrite Functions can run: an existing HTTP server, such as an
 * Express app, served as the function. Kept apart from the Sites frameworks
 * (frameworks.php) so neither list offers the other's entries.
 *
 * The runtime starts the app with `startCommand`, an Open Runtimes helper that
 * sets PORT and starts the function's entrypoint. Apps listen on process.env.PORT.
 */

use Utopia\Config\Config;

$templateRuntimes = Config::getParam('template-runtimes');

return [
    'express' => [
        'key' => 'express',
        'name' => 'Express',
        // The helper preloads its shutdown hook with NODE_OPTIONS="--import …",
        // which Node only accepts from 18 on: on older runtimes the app never starts.
        'runtimes' => \array_values(\array_filter(
            $templateRuntimes['NODE'],
            fn (string $runtime): bool => \version_compare(\substr($runtime, \strlen('node-')), '18.0', '>=')
        )),
        'entrypoint' => 'server.js',
        'installCommand' => 'npm install',
        'startCommand' => 'bash helpers/express/server.sh',
    ],
];
