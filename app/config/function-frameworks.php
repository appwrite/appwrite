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
    'nestjs' => [
        'key' => 'nestjs',
        'name' => 'NestJS',
        // NestJS needs Node 20+; on older versions the app fails at start, as with Express.
        'runtimes' => $templateRuntimes['NODE'],
        // Built to dist/ (`nest build` or tsc); the app listens on process.env.PORT.
        'entrypoint' => 'dist/main.js',
        'installCommand' => 'npm install && npm run build',
        // The Express helper starts any Node HTTP server: it runs the entrypoint with
        // PORT and HOST set, and drains the server listening on PORT on shutdown.
        'startCommand' => 'bash helpers/express/server.sh',
    ],
];
