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
        // Every Node.js version. The helper needs Node 18+ (it preloads its shutdown
        // hook with NODE_OPTIONS="--import …"): on older versions the app fails at
        // start, and those runtimes are deprecated over time.
        'runtimes' => $templateRuntimes['NODE'],
        'entrypoint' => 'server.js',
        'installCommand' => 'npm install',
        'startCommand' => 'bash helpers/express/server.sh',
    ],
];
