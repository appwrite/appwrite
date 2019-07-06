<?php

// Init
require_once __DIR__ . '/init.php';

global $env, $request, $response, $register, $consoleDB, $project, $domain, $sentry, $version, $service;

use Utopia\App;
use Utopia\Request;
use Utopia\Response;
use Utopia\Validator\Host;
use Utopia\Validator\Range;
use Utopia\View;
use Utopia\Exception;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Auth\Auth;
use Database\Document;
use Database\Validator\Authorization;
use Event\Event;

/**
 * Configuration files
 */
$roles      = include __DIR__ . '/config/roles.php'; // User roles and scopes
$providers  = include __DIR__ . '/config/providers.php'; // OAuth providers list
$sdks       = include __DIR__ . '/config/sdks.php'; // List of SDK clients

$utopia     = new App('Asia/Tel_Aviv', $env);
$webhook    = new Event('v1-webhooks', 'WebhooksV1');
$audit      = new Event('v1-audits', 'AuditsV1');
$usage      = new Event('v1-usage', 'UsageV1');

$services = [
    '/'             => [
        'name' => 'Homepage',
        'controller' => 'controllers/home.php',
        'sdk' => false,
    ],
    'console/'      => [
        'name' => 'Console',
        'controller' => 'controllers/console.php',
        'sdk' => false,
    ],
    'v1/account'    => [
        'name' => 'Account',
        'description' => 'The account service allow you to fetch and update information related to the currently logged in user. You can also retrieve a list of all the user sessions across different devices and a security log with the account recent activity.',
        'controller' => 'controllers/account.php',
        'sdk' => true,
    ],
    'v1/auth'    => [ //TODO MOVE TO AUTH CONTROLLER SCOPE
        'name' => 'Auth',
        'description' => "The authentication service allows you to verify users accounts using basic email and password login or with a supported OAuth provider. The auth service also exposes methods to confirm users email account and recover users forgotten passwords.\n\nYou can also learn how to [configure support for our supported OAuth providers](/docs/oauth). You can review our currently available OAuth providers from your project console under the **'users'** menu.",
        'controller' => 'controllers/auth.php',
        'sdk' => true,
    ],
    'v1/oauth'    => [
        'name' => 'OAuth',
        'controller' => 'controllers/auth.php',
        'sdk' => true,
    ],
    'v1/avatars'    => [
        'name' => 'Avatars',
        'description' => 'The avatars service aims to help you complete common and recitative tasks related to your app images, icons and avatars. Using this service we hope to save you some precious time and helping you focus on solving your app real challenges.',
        'controller' => 'controllers/avatars.php',
        'sdk' => true,
    ],
    'v1/database'   => [
        'name' => 'Database',
        'description' => "The database service allows you to create structured document collections, query and filter lists of documents and manage an advanced set of read and write access.
        \n\nAll the data in the database service is stored in JSON format. The service also allows you to nest child documents and use advanced filters to search and query the database just like you would with a classic graph database.
        \n\nBy leveraging the database permission management you can assign read or write access to the database documents for a specific user, team, user role or even grant public access to all visitors of your project. You can learn more about [how " . APP_NAME . " handles permissions and role access control](/docs/permissions).",
        'controller' => 'controllers/database.php',
        'sdk' => true,
    ],
    'v1/locale'        => [
        'name' => 'Locale',
        'controller' => 'controllers/locale.php',
        'sdk' => true,
    ],
    'v1/health'     => [
        'name' => 'Health',
        'controller' => 'controllers/health.php',
        'sdk' => false,
    ],
    'v1/projects'   => [
        'name' => 'Projects',
        'controller' => 'controllers/projects.php',
        'sdk' => false,
    ],
    'v1/storage'    => [
        'name' => 'Storage',
        'description' => "The storage service allows you to manage your project files. You can upload, view, download, and query your files and media.\n\nEach file is granted read and write permissions to manage who has access to view or manage it. You can also learn more about how to manage your [resources permissions](/docs/permissions).\n\n You can also use the storage file preview endpoint to show the app users preview images of your files. The preview endpoint also allows you to manipulate the resulting image, so it will fit perfectly inside your app.",
        'controller' => 'controllers/storage.php',
        'sdk' => true,
    ],
    'v1/teams'      => [
        'name' => 'Teams',
        'description' => "The teams' service allows you to group together users of your project and allow them to share read and write access to your project resources.\n\nEach user who creates a team becomes the team owner and can delegate the ownership role by inviting a new team member. Only team owners can invite new users to the team.",
        'controller' => 'controllers/teams.php',
        'sdk' => true,
    ],
    'v1/users'      => [
        'name' => 'Users',
        'controller' => 'controllers/users.php',
        'sdk' => true,
    ],
];

$utopia->init(function() use ($utopia, $request, $response, $register, &$user, $project, $consoleDB, $roles, $webhook, $audit, $usage, $domain) {

    $route = $utopia->match($request);

    /**
     * Validate SSL Connection
     */
    $https = $request->getServer('HTTP_X_FORWARDED_PROTO', $request->getServer('HTTPS', ''));

    if (empty($https) || 'off' == $https) {
        $response->redirect('https://' . $request->getServer('HTTP_HOST', '') . $request->getServer('REQUEST_URI'));
        exit(0);
    }

    $referrer   = $request->getServer('HTTP_REFERER', '');
    $origin     = $request->getServer('HTTP_ORIGIN', parse_url($referrer, PHP_URL_SCHEME) . '://' . parse_url($referrer, PHP_URL_HOST));

    $refDomain = (in_array($origin, array_merge($project->getAttribute('clients', []))))
        ? $origin : 'http://localhost';

    /**
     * Security Headers
     *
     * As recommended at:
     * @see https://www.owasp.org/index.php/List_of_useful_HTTP_headers
     */
    $response
        ->addHeader('Server', 'Appwrite')
        ->addHeader('X-XSS-Protection', '1; mode=block; report=/v1/xss?url=' . urlencode($request->getServer('REQUEST_URI')))
        ->addHeader('Strict-Transport-Security', 'max-age=16070400')
        //->addHeader('X-Frame-Options', ($refDomain == 'http://localhost') ? 'SAMEORIGIN' : 'ALLOW-FROM ' . $refDomain)
        ->addHeader('X-Content-Type-Options', 'nosniff')
        ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
        ->addHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Ajax, Origin, Content-Type, Accept, X-Appwrite-Project, X-Appwrite-Key, X-SDK-Version')
        ->addHeader('Access-Control-Allow-Origin', $refDomain)
        ->addHeader('Access-Control-Allow-Credentials', 'true')
    ;

    /**
     * Validate Client Domain - Check to avoid CSRF attack
     *  Adding appwrite api domains to allow XDOMAIN communication
     */
    $hostValidator = new Host(array_merge($project->getAttribute('clients', []), ['http://localhost', 'https://localhost', 'https://appwrite.test', 'https://appwrite.io']));

    if(!$hostValidator->isValid($request->getServer('HTTP_ORIGIN', $request->getServer('HTTP_REFERER', '')))
        && in_array($request->getMethod(), [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_PATCH, Request::METHOD_DELETE])
        && empty($request->getHeader('X-Appwrite-Key', ''))) {
        throw new Exception('Access from this client host is forbidden. ' . $hostValidator->getDescription(), 403);
    }

    /**
     * ACL Check
     */
    $role = ($user->isEmpty()) ? Auth::USER_ROLE_GUEST : Auth::USER_ROLE_MEMBER;

    // Add user roles
    $membership = $user->search('teamId', $project->getAttribute('teamId', null), $user->getAttribute('memberships', []));

    if($membership) {
        foreach ($membership->getAttribute('roles', []) as $memberRole) {
            switch ($memberRole) {
                case 'owner':
                    $role = Auth::USER_ROLE_OWNER;
                    break;
                case 'admin':
                    $role = Auth::USER_ROLE_ADMIN;
                    break;
                case 'developer':
                    $role = Auth::USER_ROLE_DEVELOPER;
                    break;
            }
        }
    }

    $scope      = $route->getLabel('scope', 'none'); // Allowed scope for chosen route
    $scopes     = $roles[$role]['scopes']; // Allowed scopes for user role

    // Check if given key match project API keys
    $key = $project->search('secret', $request->getHeader('X-Appwrite-Key', ''), $project->getAttribute('keys', []));

    /**
     * Try app auth when we have project key and no user
     *  Mock user to app and grant API key scopes in addition to default app scopes
     */
    if(null !== $key && $user->isEmpty()) {
        $user = new Document([
            '$uid' 	        => 0,
            'status' 	    => Auth::USER_STATUS_ACTIVATED,
            'email' 	    => 'app.' . $project->getUid() . '@service.' . $domain,
            'password' 	    => '',
            'name'	        => $project->getAttribute('name', 'Untitled'),
        ]);

        $role   = Auth::USER_ROLE_APP;
        $scopes = array_merge($roles[$role]['scopes'], $key->getAttribute('scopes', []));

        Authorization::disable();  // Cancel security segmentation for API keys.
    }

    Authorization::setRole('user:' . $user->getUid());
    Authorization::setRole('role:' . $role);

    array_map(function ($node) {
        if(isset($node['teamId']) && isset($node['roles'])) {
            Authorization::setRole('team:' . $node['teamId']);

            foreach ($node['roles'] as $nodeRole) { // Set all team roles
                Authorization::setRole('team:' . $node['teamId'] . '/' . $nodeRole);
            }
        }
    }, $user->getAttribute('memberships', []));

    if(!in_array($scope, $scopes)) {
        throw new Exception($user->getAttribute('email', 'Guest') .  ' (role: ' . strtolower($roles[$role]['label']) . ') missing scope (' . $scope . ')', 401);
    }

    if(Auth::USER_STATUS_BLOCKED == $user->getAttribute('status')) { // Account has not been activated
        throw new Exception('Invalid credentials. User is blocked', 401); // User is in status blocked
    }

    if($user->getAttribute('reset')) {
        throw new Exception('Password reset is required', 412);
    }

    /**
     * Background Jobs
     */
    $webhook
        ->setParam('projectId', $project->getUid())
        ->setParam('event', $route->getLabel('webhook', ''))
        ->setParam('payload', [])
    ;

    $audit
        ->setParam('projectId', $project->getUid())
        ->setParam('userId', $user->getUid())
        ->setParam('event', '')
        ->setParam('resource', '')
        ->setParam('userAgent', $request->getServer('HTTP_USER_AGENT', ''))
        ->setParam('ip', $request->getIP())
        ->setParam('data', [])
    ;

    $usage
        ->setParam('projectId', $project->getUid())
        ->setParam('url', $request->getServer('HTTP_HOST', '') . $request->getServer('REQUEST_URI', ''))
        ->setParam('method', $request->getServer('REQUEST_METHOD', 'UNKNOWN'))
        ->setParam('request', 0)
        ->setParam('response', 0)
        ->setParam('storage', 0)
    ;

    /**
     * Abuse Check
     */
    $timeLimit = new TimeLimit($route->getLabel('abuse-key', 'url:{url},ip:{ip}'), $route->getLabel('abuse-limit', 0), $route->getLabel('abuse-time', 3600), function () use ($register) {return $register->get('db');});
    $timeLimit->setNamespace('app_' . $project->getUid());
    $timeLimit
        ->setParam('{userId}', $user->getUid())
        ->setParam('{userAgent}', $request->getServer('HTTP_USER_AGENT', ''))
        ->setParam('{ip}', $request->getIP())
        ->setParam('{url}', $request->getServer('HTTP_HOST', '') . $route->getURL())
    ;

    //TODO make sure we get array here

    foreach($request->getParams() as $key => $value) { // Set request params as potential abuse keys
        $timeLimit->setParam('{param-' . $key . '}', (is_array($value)) ? json_encode($value) : $value);
    }

    $abuse = new Abuse($timeLimit);

    $response
        ->addHeader('X-RateLimit-Limit', $timeLimit->limit())
        ->addHeader('X-RateLimit-Remaining', $timeLimit->remaining())
        ->addHeader('X-RateLimit-Reset', $timeLimit->time() + $route->getLabel('abuse-time', 3600))
    ;

    if($abuse->check()) {
        throw new Exception('Too many requests', 429);
    }
});

$utopia->shutdown(function () use ($response, $request, $webhook, $audit, $usage) {

    /**
     * Trigger Events for background jobs
     */
    if(!empty($webhook->getParam('event'))) {
        $webhook->trigger();
    }

    if(!empty($audit->getParam('event'))) {
        $audit->trigger();
    }

    $usage
        ->setParam('request', $request->getSize())
        ->setParam('response', $response->getSize())
        ->trigger()
    ;
});

$utopia->options(function() use ($request, $response, $domain, $project) {
    $origin = $request->getServer('HTTP_ORIGIN');

    $response
        ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
        ->addHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Ajax, Origin, Content-Type, Accept, X-Appwrite-Project, X-Appwrite-Key, X-SDK-Version')
        ->addHeader('Access-Control-Allow-Origin', $origin)
        ->addHeader('Access-Control-Allow-Credentials', 'true')
        ->send();
    ;
});

$utopia->error(function($error /* @var $error Exception */) use ($request, $response, $utopia, $project, $env, $version, $sentry, $user) {
    switch($error->getCode()) {
        case 400: // Error allowed publicly
        case 401: // Error allowed publicly
        case 402: // Error allowed publicly
        case 403: // Error allowed publicly
        case 404: // Error allowed publicly
        case 412: // Error allowed publicly
        case 429: // Error allowed publicly
            $code       = $error->getCode();
            $message    = $error->getMessage();
            break;
        default:
            $code       = 500; // All other errors get the generic 500 server error status code
            $message    = 'Server Error';
    }

    $_SERVER = []; // Reset before reporting to error log to avoid keys being compromised

    $errorID = (App::ENV_TYPE_PRODUCTION == $env) ? $sentry->captureException($error) : null;

    if (extension_loaded('newrelic') && (App::ENV_TYPE_PRODUCTION == $env)) { // Ensure PHP agent is available
        newrelic_notice_error($error->getMessage(), $error);
    }

    $whiteUsers = [
        'eldad.fux@gmail.com',
        'eldad@appwrite.io',
        'eldad@careerpage.io',
    ];

    $output = ((App::ENV_TYPE_DEVELOPMENT == $env) || (in_array($user->getAttribute('email'), $whiteUsers))) ? [
        'message'   => $error->getMessage(),
        'code'      => $error->getCode(),
        'errorID'   => $errorID,
        'file'      => $error->getFile(),
        'line'      => $error->getLine(),
        'trace'     => $error->getTrace(),
        'version'   => $version,
    ] : [
        'message' => $message,
        'code'    => $code,
        'errorID' => $errorID,
        'version'   => $version,
    ];

    $response
        ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->addHeader('Expires', '0')
        ->addHeader('Pragma', 'no-cache')
        ->setStatusCode($code)
    ;

    $route      = $utopia->match($request);
    $template   = ($route) ? $route->getLabel('error', null): null;

    if($template) {
        $layout     = new View(__DIR__ . '/views/layouts/default.phtml');
        $comp       = new View($template);

        $comp
            ->setParam('projectName', $project->getAttribute('name'))
            ->setParam('projectURL', $project->getAttribute('url'))
            ->setParam('message', $error->getMessage())
            ->setParam('code', $code)
            ->setParam('errorID', $errorID)
        ;

        $layout
            ->setParam('title', $project->getAttribute('name') . ' - Error')
            ->setParam('description', 'No Description')
            ->setParam('body', $comp)
            ->setParam('version', $version)
            ->setParam('litespeed', false)
        ;

        $response->send($layout->render());
    }

    $response
        ->json($output)
    ;
});

$utopia->get('/v1/info') // This is only visible to gods
    ->label('scope', 'god')
    ->label('docs', false)
    ->action(
        function() use ($request, $response, $user, $project, $version, $env) { //TODO CONSIDER BLOCKING THIS ACTION TO ROLE GOD
            $response->json([
                'name'          => 'API',
                'version'       => $version,
                'environment'   => $env,
                'time'          => date('Y-m-d H:i:s', time()),
                'user'          => [
                    'id' => $user->getUid(),
                    'name' => $user->getAttribute('name', ''),
                ],
                'project'       => [
                    'id' => $project->getUid(),
                    'name' => $project->getAttribute('name', ''),
                ],
            ]);
        }
    );

$utopia->get('/v1/xss')
    ->desc('Log XSS errors reported by browsers using X-XSS-Protection header')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(
        function() use ($response, $project) {
            throw new Exception('XSS detected and reported by a browser client', 500);
        }
    );

$utopia->get('/v1/proxy')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(
        function() use ($response, $project, $console) {
            $view = new View(__DIR__ . '/views/proxy.phtml');
            $view
                ->setParam('routes', '')
                ->setParam('clients', array_merge($project->getAttribute('clients', []), $console->getAttribute('clients', [])))
            ;

            $response
                ->setContentType(Response::CONTENT_TYPE_HTML)
                ->removeHeader('X-Frame-Options')
                ->send($view->render());
        }
    );

$utopia->get('/v1/docs')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(
        function() use ($response, $utopia) {
            $view = new View(__DIR__ . '/views/docs.phtml');
            $view
                ->setParam('routes', $utopia->getRoutes())
            ;

            $response->send($view->render());
        }
    );

$utopia->get('/v1/server') // This is only visible to gods
    ->label('scope', 'god')
    ->label('docs', false)
    ->action(
        function() use ($response) {
            $response->json($_SERVER);
        }
    );

$utopia->get('/v1/open-api-2.json')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('extensions', 0 , function () {return new Range(0, 1);}, 'Show extra data.', true)
    ->action(
        function($extensions) use ($response, $utopia, $domain, $version, $services, $consoleDB) {

            function fromCamelCase($input) {
                preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
                $ret = $matches[0];
                foreach ($ret as &$match) {
                    $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
                }
                return implode('_', $ret);
            }

            function fromCamelCaseToDash($input) {
                preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
                $ret = $matches[0];
                foreach ($ret as &$match) {
                    $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
                }
                return implode('-', $ret);
            }

            /*$scopes = [
                'client' => [
                    'name'      => 'Client',
                    'auth'      => [],
                    'services'  => [],
                ],
                'server' => [
                    'name'      => 'Server',
                    'auth'      => [],
                    'services'  => [],
                ],
                'admin' => [
                    'name'      => 'Admin',
                    'auth'      => [],
                    'services'  => [],
                ],
            ];*/

            foreach ($services as $service) { /** @noinspection PhpIncludeInspection */
                if(!$service['sdk']) {
                    continue;
                }

                /** @noinspection PhpIncludeInspection */
                include_once $service['controller'];
            }

            /**
             * Specifications (v3.0.0):
             * https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.0.md
             */
            $output = [
                'swagger' => '2.0',
                'info' => [
                    'version' => $version,
                    'title' => APP_NAME,
                    'description' => 'Appwrite backend as a service cuts up to 70% of the time and costs required for building a modern application. We abstract and simplify common development tasks behind a REST APIs, to help you develop your app in a fast and secure way. For full API documentation and tutorials go to [https://appwrite.io/docs](https://appwrite.io/docs)',
                    'termsOfService' => 'https://appwrite.io/policy/terms',
                    'contact' => [
                        'name' => 'Appwrite Team',
                        'url' => 'https://appwrite.io/support',
                        'email' => APP_EMAIL_TEAM,
                    ],
                    'license' => [
                        'name' => 'BSD-3-Clause',
                        'url' => 'https://raw.githubusercontent.com/appwrite/appwrite/master/LICENSE',
                    ],
                ],
                'host' => $domain,
                'basePath' => '/v1',
                'schemes' => ['https'],
                'consumes' => ['application/json', 'application/x-www-form-urlencoded'],
                'produces' => ['application/json'],
                'securityDefinitions' => [
                    'Project' => [
                        'type' => 'apiKey',
                        'name' => 'X-Appwrite-Project',
                        'description' => 'Your Appwrite project ID. You can find your project ID in your Appwrite console project settings.',
                        'in' => 'header',
                    ],
                    'Key' => [
                        'type' => 'apiKey',
                        'name' => 'X-Appwrite-Key',
                        'description' => 'Your Appwrite project secret key. You can can create a new API key from your Appwrite console API keys dashboard.',
                        'in' => 'header',
                    ],
                    'Locale' => [
                        'type' => 'apiKey',
                        'name' => 'X-Appwrite-Locale',
                        'description' => '',
                        'in' => 'header',
                    ],
                    'Mode' => [
                        'type' => 'apiKey',
                        'name' => 'X-Appwrite-Mode',
                        'description' => '',
                        'in' => 'header',
                    ],
                ],
                'paths' => [],
                'definitions' => array (
                        'Pet' =>
                            array (
                                'required' =>
                                    array (
                                        0 => 'id',
                                        1 => 'name',
                                    ),
                                'properties' =>
                                    array (
                                        'id' =>
                                            array (
                                                'type' => 'integer',
                                                'format' => 'int64',
                                            ),
                                        'name' =>
                                            array (
                                                'type' => 'string',
                                            ),
                                        'tag' =>
                                            array (
                                                'type' => 'string',
                                            ),
                                    ),
                            ),
                        'Pets' =>
                            array (
                                'type' => 'array',
                                'items' =>
                                    array (
                                        '$ref' => '#/definitions/Pet',
                                    ),
                            ),
                        'Error' =>
                            array (
                                'required' =>
                                    array (
                                        0 => 'code',
                                        1 => 'message',
                                    ),
                                'properties' =>
                                    array (
                                        'code' =>
                                            array (
                                                'type' => 'integer',
                                                'format' => 'int32',
                                            ),
                                        'message' =>
                                            array (
                                                'type' => 'string',
                                            ),
                                    ),
                            ),
                    ),
                'externalDocs' => [
                    'description' => 'Full API docs, specs and tutorials',
                    'url' => APP_PROTOCOL . '://' . $domain . '/docs'
                ]
            ];

            foreach ($utopia->getRoutes() as $key => $method) {
                foreach ($method as $route) { /* @var $route \Utopia\Route */
                    if(!$route->getLabel('docs', true)) {
                        continue;
                    }

                    if(empty($route->getLabel('sdk.namespace', null))) {
                        continue;
                    }

                    $url    = str_replace('/v1', '', $route->getURL());
                    $scope  = $route->getLabel('scope', '');
                    $hide   = $route->getLabel('sdk.hide', false);

                    if($hide) {
                        continue;
                    }

                    $temp = [
                        'summary' => $route->getDesc(),
                        'operationId' => $route->getLabel('sdk.method', uniqid()),
                        'tags' => [$route->getLabel('sdk.namespace', 'default')],
                        'description' => $route->getLabel('sdk.description', ''),
                        'responses' => [
                            200 => [
                                'description' => 'An paged array of pets',
                                'schema' => [
                                    '$ref' => '#/definitions/Pet'
                                ],
                            ],
                        ],
                    ];

                    if($extensions) {
                        $temp['extensions'] = [
                            'weight' => $route->getOrder(),
                            'cookies' => $route->getLabel('sdk.cookies', false),
                            'demo' => 'docs/examples/' . fromCamelCaseToDash($route->getLabel('sdk.namespace', 'default')) . '/' . fromCamelCaseToDash($temp['operationId']) . '.md',
                        ];
                    }

                    if((!empty($scope) && 'public' != $scope)) {
                        $temp['security'][] = ['Project' => [], 'Key' => []];
                    }

                    $requestBody = [
                        'content' => [
                            'application/x-www-form-urlencoded' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [],
                                ],
                                'required' => [],
                            ]
                        ]
                    ];

                    foreach ($route->getParams() as $name => $param) {
                        $validator = (is_callable($param['validator'])) ? $param['validator']() : $param['validator']; /* @var $validator \Utopia\Validator */

                        $node = [
                            'name' => $name,
                            'description' => $param['description'],
                            'required' => !$param['optional'],
                        ];

                        switch ((!empty($validator)) ? get_class($validator) : '') {
                            case 'Utopia\Validator\Text':
                                $node['type'] = 'string';
                                $node['x-example'] = '[' . strtoupper(fromCamelCase($node['name'])) . ']';
                                break;
                            case 'Database\Validator\UID':
                                $node['type'] = 'string';
                                $node['x-example'] = '[' . strtoupper(fromCamelCase($node['name'])) . ']';
                                break;
                            case 'Utopia\Validator\Email':
                                $node['type'] = 'string';
                                $node['format'] = 'email';
                                $node['x-example'] = 'email@example.com';
                                break;
                            case 'Utopia\Validator\URL':
                                $node['type'] = 'string';
                                $node['format'] = 'url';
                                $node['x-example'] = 'https://example.com';
                                break;
                            case 'Utopia\Validator\JSON':
                            case 'Utopia\Validator\Mock':
                                $node['type'] = 'object';
                                $node['type'] = 'string';
                                $node['x-example'] = '{}';
                                //$node['format'] = 'json';
                                break;
                            case 'Storage\Validators\File':
                                $node['type'] = 'file';
                                $temp['consumes'] = ['multipart/form-data'];
                                break;
                            case 'Utopia\Validator\ArrayList':
                                $node['type'] = 'array';
                                $node['collectionFormat'] = 'multi';
                                $node['items'] = [
                                    'type' => 'string'
                                ];
                                break;
                            case 'Auth\Validator\Password':
                                $node['type'] = 'string';
                                $node['format'] = 'format';
                                $node['x-example'] = 'password';
                                break;
                            case 'Utopia\Validator\Range': /* @var $validator \Utopia\Validator\Range */
                                $node['type'] = 'integer';
                                $node['format'] = 'int32';
                                $node['x-example'] = rand($validator->getMin(), $validator->getMax());
                                break;
                            case 'Utopia\Validator\Numeric':
                                $node['type'] = 'integer';
                                $node['format'] = 'int32';
                                break;
                            case 'Utopia\Validator\Length':
                                $node['type'] = 'string';
                                break;
                            case 'Utopia\Validator\Host':
                                $node['type'] = 'string';
                                $node['format'] = 'url';
                                $node['x-example'] = 'https://example.com';
                                break;
                            case 'Utopia\Validator\WhiteList': /* @var $validator \Utopia\Validator\WhiteList */
                                $node['type'] = 'string';
                                $node['x-example'] = $validator->getList()[0];
                                break;
                            default:
                                $node['type'] = 'string';
                                break;
                        }

                        if($param['optional'] && !is_null($param['default'])) { // Param has default value
                            $node['default'] = $param['default'];
                        }

                        if (false !== strpos($url, ':' . $name)) { // Param is in URL path
                            $node['in'] = 'path';
                            $temp['parameters'][] = $node;
                        }
                        elseif ($key == 'GET') { // Param is in query
                            $node['in'] = 'query';
                            $temp['parameters'][] = $node;
                        }
                        else { // Param is in payload
                            $node['in'] = 'formData';
                            $temp['parameters'][] = $node;
                            $requestBody['content']['application/x-www-form-urlencoded']['schema']['properties'][] = $node;

                            if(!$param['optional']) {
                                $requestBody['content']['application/x-www-form-urlencoded']['required'][] = $name;
                            }
                        }

                        $url = str_replace(':' . $name, '{' . $name . '}', $url);
                    }

                    $output['paths'][$url][strtolower($route->getMethod())] = $temp;
                }
            }

            /*foreach ($consoleDB->getMocks() as $mock) {
                var_dump($mock['name']);
            }*/

            ksort($output['paths']);

            $response->json($output);
        }
    );

$name = APP_NAME;

if(array_key_exists($service, $services)) { /** @noinspection PhpIncludeInspection */
    include_once $services[$service]['controller'];
    $name = APP_NAME . ' ' . ucfirst($services[$service]['name']);
}
else {
    /** @noinspection PhpIncludeInspection */
    include_once $services['/']['controller'];
}

if (extension_loaded('newrelic')) {
    $route  = $utopia->match($request);
    $url    = (!empty($route)) ? $route->getURL() : '/error';

    newrelic_set_appname($name);
    newrelic_name_transaction($request->getServer('REQUEST_METHOD', 'UNKNOWN') . ': ' . $url);
}

$utopia->run($request, $response);