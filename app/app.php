<?php

// Init
require_once __DIR__.'/init.php';

global $env, $utopia, $request, $response, $register, $consoleDB, $project, $domain, $sentry, $version, $service, $providers;

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
use Utopia\Validator\WhiteList;

/*
 * Configuration files
 */
$roles = include __DIR__.'/config/roles.php'; // User roles and scopes
$sdks = include __DIR__.'/config/sdks.php'; // List of SDK clients
$services = include __DIR__.'/config/services.php'; // List of SDK clients

$webhook = new Event('v1-webhooks', 'WebhooksV1');
$audit = new Event('v1-audits', 'AuditsV1');
$usage = new Event('v1-usage', 'UsageV1');

$clientsConsole = array_map(function ($node) {
    return $node['url'];
}, array_filter($console->getAttribute('platforms', []), function ($node) {
    if (isset($node['type']) && $node['type'] === 'web' && isset($node['url']) && !empty($node['url'])) {
        return true;
    }

    return false;
}));

$clients = array_unique(array_merge($clientsConsole, array_map(function ($node) {
    return $node['url'];
}, array_filter($project->getAttribute('platforms', []), function ($node) {
    if (isset($node['type']) && $node['type'] === 'web' && isset($node['url']) && !empty($node['url'])) {
        return true;
    }

    return false;
}))));

$utopia->init(function () use ($utopia, $request, $response, $register, &$user, $project, $roles, $webhook, $audit, $usage, $domain, $clients) {
    $route = $utopia->match($request);

    $referrer = $request->getServer('HTTP_REFERER', '');
    $origin = $request->getServer('HTTP_ORIGIN', parse_url($referrer, PHP_URL_SCHEME).'://'.parse_url($referrer, PHP_URL_HOST));

    $refDomain = (in_array($origin, $clients))
        ? $origin : 'http://localhost';

    /*
     * Security Headers
     *
     * As recommended at:
     * @see https://www.owasp.org/index.php/List_of_useful_HTTP_headers
     */
    $response
        ->addHeader('Server', 'Appwrite')
        ->addHeader('X-XSS-Protection', '1; mode=block; report=/v1/xss?url='.urlencode($request->getServer('REQUEST_URI')))
        //->addHeader('X-Frame-Options', ($refDomain == 'http://localhost') ? 'SAMEORIGIN' : 'ALLOW-FROM ' . $refDomain)
        ->addHeader('X-Content-Type-Options', 'nosniff')
        ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
        ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-SDK-Version')
        ->addHeader('Access-Control-Allow-Origin', $refDomain)
        ->addHeader('Access-Control-Allow-Credentials', 'true')
    ;

    /*
     * Validate Client Domain - Check to avoid CSRF attack
     *  Adding appwrite api domains to allow XDOMAIN communication
     */
    $hostValidator = new Host($clients);
    $origin = $request->getServer('HTTP_ORIGIN', $request->getServer('HTTP_REFERER', ''));
    
    if (!$hostValidator->isValid($origin)
        && in_array($request->getMethod(), [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_PATCH, Request::METHOD_DELETE])
        && empty($request->getHeader('X-Appwrite-Key', ''))) {
        throw new Exception('Access from this client host is forbidden. '.$hostValidator->getDescription(), 403);
    }

    /*
     * ACL Check
     */
    $role = ($user->isEmpty()) ? Auth::USER_ROLE_GUEST : Auth::USER_ROLE_MEMBER;

    // Add user roles
    $membership = $user->search('teamId', $project->getAttribute('teamId', null), $user->getAttribute('memberships', []));

    if ($membership) {
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

    $scope = $route->getLabel('scope', 'none'); // Allowed scope for chosen route
    $scopes = $roles[$role]['scopes']; // Allowed scopes for user role

    // Check if given key match project API keys
    $key = $project->search('secret', $request->getHeader('X-Appwrite-Key', ''), $project->getAttribute('keys', []));

    /*
     * Try app auth when we have project key and no user
     *  Mock user to app and grant API key scopes in addition to default app scopes
     */
    if (null !== $key && $user->isEmpty()) {
        $user = new Document([
            '$uid' => 0,
            'status' => Auth::USER_STATUS_ACTIVATED,
            'email' => 'app.'.$project->getUid().'@service.'.$domain,
            'password' => '',
            'name' => $project->getAttribute('name', 'Untitled'),
        ]);

        $role = Auth::USER_ROLE_APP;
        $scopes = array_merge($roles[$role]['scopes'], $key->getAttribute('scopes', []));

        Authorization::disable();  // Cancel security segmentation for API keys.
    }

    Authorization::setRole('user:'.$user->getUid());
    Authorization::setRole('role:'.$role);

    array_map(function ($node) {
        if (isset($node['teamId']) && isset($node['roles'])) {
            Authorization::setRole('team:'.$node['teamId']);

            foreach ($node['roles'] as $nodeRole) { // Set all team roles
                Authorization::setRole('team:'.$node['teamId'].'/'.$nodeRole);
            }
        }
    }, $user->getAttribute('memberships', []));

    if (!in_array($scope, $scopes)) {
        throw new Exception($user->getAttribute('email', 'Guest').' (role: '.strtolower($roles[$role]['label']).') missing scope ('.$scope.')', 401);
    }

    if (Auth::USER_STATUS_BLOCKED == $user->getAttribute('status')) { // Account has not been activated
        throw new Exception('Invalid credentials. User is blocked', 401); // User is in status blocked
    }

    if ($user->getAttribute('reset')) {
        throw new Exception('Password reset is required', 412);
    }

    /*
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
        ->setParam('url', $request->getServer('HTTP_HOST', '').$request->getServer('REQUEST_URI', ''))
        ->setParam('method', $request->getServer('REQUEST_METHOD', 'UNKNOWN'))
        ->setParam('request', 0)
        ->setParam('response', 0)
        ->setParam('storage', 0)
    ;

    /*
     * Abuse Check
     */
    $timeLimit = new TimeLimit($route->getLabel('abuse-key', 'url:{url},ip:{ip}'), $route->getLabel('abuse-limit', 0), $route->getLabel('abuse-time', 3600), function () use ($register) {
        return $register->get('db');
    });
    $timeLimit->setNamespace('app_'.$project->getUid());
    $timeLimit
        ->setParam('{userId}', $user->getUid())
        ->setParam('{userAgent}', $request->getServer('HTTP_USER_AGENT', ''))
        ->setParam('{ip}', $request->getIP())
        ->setParam('{url}', $request->getServer('HTTP_HOST', '').$route->getURL())
    ;

    //TODO make sure we get array here

    foreach ($request->getParams() as $key => $value) { // Set request params as potential abuse keys
        $timeLimit->setParam('{param-'.$key.'}', (is_array($value)) ? json_encode($value) : $value);
    }

    $abuse = new Abuse($timeLimit);

    if ($timeLimit->limit()) {
        $response
            ->addHeader('X-RateLimit-Limit', $timeLimit->limit())
            ->addHeader('X-RateLimit-Remaining', $timeLimit->remaining())
            ->addHeader('X-RateLimit-Reset', $timeLimit->time() + $route->getLabel('abuse-time', 3600))
        ;
    }

    if ($abuse->check() && $request->getServer('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled') {
        throw new Exception('Too many requests', 429);
    }
});

$utopia->shutdown(function () use ($response, $request, $webhook, $audit, $usage) {

    /*
     * Trigger Events for background jobs
     */
    if (!empty($webhook->getParam('event'))) {
        $webhook->trigger();
    }

    if (!empty($audit->getParam('event'))) {
        $audit->trigger();
    }

    $usage
        ->setParam('request', $request->getSize())
        ->setParam('response', $response->getSize())
        ->trigger()
    ;
});

$utopia->options(function () use ($request, $response, $domain, $project) {
    $origin = $request->getServer('HTTP_ORIGIN');

    $response
        ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
        ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-SDK-Version')
        ->addHeader('Access-Control-Allow-Origin', $origin)
        ->addHeader('Access-Control-Allow-Credentials', 'true')
        ->send();
});

$utopia->error(function ($error /* @var $error Exception */) use ($request, $response, $utopia, $project, $env, $version, $sentry, $user) {
    switch ($error->getCode()) {
        case 400: // Error allowed publicly
        case 401: // Error allowed publicly
        case 402: // Error allowed publicly
        case 403: // Error allowed publicly
        case 404: // Error allowed publicly
        case 412: // Error allowed publicly
        case 429: // Error allowed publicly
            $code = $error->getCode();
            $message = $error->getMessage();
            break;
        default:
            $code = 500; // All other errors get the generic 500 server error status code
            $message = 'Server Error';
    }

    $_SERVER = []; // Reset before reporting to error log to avoid keys being compromised

    $output = ((App::ENV_TYPE_DEVELOPMENT == $env)) ? [
        'message' => $error->getMessage(),
        'code' => $error->getCode(),
        'file' => $error->getFile(),
        'line' => $error->getLine(),
        'trace' => $error->getTrace(),
        'version' => $version,
    ] : [
        'message' => $message,
        'code' => $code,
        'version' => $version,
    ];

    $response
        ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->addHeader('Expires', '0')
        ->addHeader('Pragma', 'no-cache')
        ->setStatusCode($code)
    ;

    $route = $utopia->match($request);
    $template = ($route) ? $route->getLabel('error', null) : null;

    if ($template) {
        $layout = new View(__DIR__.'/views/layouts/default.phtml');
        $comp = new View($template);

        $comp
            ->setParam('projectName', $project->getAttribute('name'))
            ->setParam('projectURL', $project->getAttribute('url'))
            ->setParam('message', $error->getMessage())
            ->setParam('code', $code)
        ;

        $layout
            ->setParam('title', $project->getAttribute('name').' - Error')
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

$utopia->get('/manifest.json')
    ->desc('Progressive app manifest file')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(
        function () use ($response) {
            $response->json([
                'name' => APP_NAME,
                'short_name' => APP_NAME,
                'start_url' => '.',
                'url' => 'https://appwrite.io/',
                'display' => 'standalone',
                'background_color' => '#fff',
                'theme_color' => '#f02e65',
                'description' => 'End to end backend server for frontend and mobile apps. 👩‍💻👨‍💻',
                'icons' => [
                    [
                        'src' => 'images/favicon.png',
                        'sizes' => '256x256',
                        'type' => 'image/png',
                    ],
                ],
            ]);
        }
    );

$utopia->get('/robots.txt')
    ->desc('Robots.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(
        function () use ($response) {
            $response->text('# robotstxt.org/

User-agent: *
');
        }
    );

$utopia->get('/humans.txt')
    ->desc('Humans.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(
        function () use ($response) {
            $response->text('# humanstxt.org/
# The humans responsible & technology colophon

# TEAM
    <name> -- <role> -- <twitter>

# THANKS
    <name>');
        }
    );

$utopia->get('/v1/info') // This is only visible to gods
->label('scope', 'god')
    ->label('docs', false)
    ->action(
        function () use ($response, $user, $project, $version, $env) { //TODO CONSIDER BLOCKING THIS ACTION TO ROLE GOD
            $response->json([
                'name' => 'API',
                'version' => $version,
                'environment' => $env,
                'time' => date('Y-m-d H:i:s', time()),
                'user' => [
                    'id' => $user->getUid(),
                    'name' => $user->getAttribute('name', ''),
                ],
                'project' => [
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
        function () {
            throw new Exception('XSS detected and reported by a browser client', 500);
        }
    );

$utopia->get('/v1/proxy')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(
        function () use ($response, $console, $clients) {
            $view = new View(__DIR__.'/views/proxy.phtml');
            $view
                ->setParam('routes', '')
                ->setParam('clients', array_merge($clients, $console->getAttribute('clients', [])))
            ;

            $response
                ->setContentType(Response::CONTENT_TYPE_HTML)
                ->removeHeader('X-Frame-Options')
                ->send($view->render());
        }
    );

$utopia->get('/v1/open-api-2.json')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('platform', 'client', function () {return new WhiteList(['client', 'server']);}, 'Choose target platform.', true)
    ->param('extensions', 0, function () {return new Range(0, 1);}, 'Show extra data.', true)
    ->param('tests', 0, function () {return new Range(0, 1);}, 'Include only test services.', true)
    ->action(
        function ($platform, $extensions, $tests) use ($response, $request, $utopia, $domain, $services) {
            function fromCamelCase($input)
            {
                preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
                $ret = $matches[0];
                foreach ($ret as &$match) {
                    $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
                }

                return implode('_', $ret);
            }

            function fromCamelCaseToDash($input)
            {
                return str_replace([' ', '_'], '-', strtolower(preg_replace('/([a-zA-Z])(?=[A-Z])/', '$1-', $input)));
            }

            foreach ($services as $service) { /* @noinspection PhpIncludeInspection */
                if($tests && !$service['tests']) {
                    continue;
                }
                
                if (!$tests && !$service['sdk']) {
                    continue;
                }

                /** @noinspection PhpIncludeInspection */
                include_once $service['controller'];
            }

            $security = [
                'client' => ['Project' => []],
                'server' => ['Project' => [], 'Key' => []],
            ];

            /*
             * Specifications (v3.0.0):
             * https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.0.md
             */
            $output = [
                'swagger' => '2.0',
                'info' => [
                    'version' => APP_VERSION_STABLE,
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
                'host' => parse_url($request->getServer('_APP_HOME', $domain), PHP_URL_HOST),
                'basePath' => '/v1',
                'schemes' => ['https'],
                'consumes' => ['application/json', 'multipart/form-data'],
                'produces' => ['application/json'],
                'securityDefinitions' => [
                    'Project' => [
                        'type' => 'apiKey',
                        'name' => 'X-Appwrite-Project',
                        'description' => 'Your Appwrite project ID',
                        'in' => 'header',
                    ],
                    'Key' => [
                        'type' => 'apiKey',
                        'name' => 'X-Appwrite-Key',
                        'description' => 'Your Appwrite project secret key',
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
                'definitions' => [
                    'Pet' => [
                        'required' => ['id', 'name'],
                        'properties' => [
                            'id' => [
                                'type' => 'integer',
                                'format' => 'int64',
                            ],
                            'name' => [
                                'type' => 'string',
                            ],
                            'tag' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'Pets' => array(
                            'type' => 'array',
                            'items' => array(
                                    '$ref' => '#/definitions/Pet',
                                ),
                        ),
                    'Error' => array(
                            'required' => array(
                                    0 => 'code',
                                    1 => 'message',
                                ),
                            'properties' => array(
                                    'code' => array(
                                            'type' => 'integer',
                                            'format' => 'int32',
                                        ),
                                    'message' => array(
                                            'type' => 'string',
                                        ),
                                ),
                        ),
                ],
                'externalDocs' => [
                    'description' => 'Full API docs, specs and tutorials',
                    'url' => $request->getServer('REQUEST_SCHEME', 'https').'://'.$domain.'/docs',
                ],
            ];

            foreach ($utopia->getRoutes() as $key => $method) {
                foreach ($method as $route) { /* @var $route \Utopia\Route */
                    if (!$route->getLabel('docs', true)) {
                        continue;
                    }

                    if (empty($route->getLabel('sdk.namespace', null))) {
                        continue;
                    }

                    $url = str_replace('/v1', '', $route->getURL());
                    $scope = $route->getLabel('scope', '');
                    $hide = $route->getLabel('sdk.hide', false);
                    $consumes = ['application/json'];

                    if ($hide) {
                        continue;
                    }

                    $desc = realpath(__DIR__ . '/..' . $route->getLabel('sdk.description', ''));

                    $temp = [
                        'summary' => $route->getDesc(),
                        'operationId' => $route->getLabel('sdk.method', uniqid()),
                        'consumes' => [],
                        'tags' => [$route->getLabel('sdk.namespace', 'default')],
                        'description' => ($desc) ? file_get_contents($desc) : '',
                        'responses' => [
                            200 => [
                                'description' => 'An paged array of pets',
                                'schema' => [
                                    '$ref' => '#/definitions/Pet',
                                ],
                            ],
                        ],
                    ];

                    if ($extensions) {
                        $temp['extensions'] = [
                            'weight' => $route->getOrder(),
                            'cookies' => $route->getLabel('sdk.cookies', false),
                            'location' => $route->getLabel('sdk.location', false),
                            'demo' => 'docs/examples/'.fromCamelCaseToDash($route->getLabel('sdk.namespace', 'default')).'/'.fromCamelCaseToDash($temp['operationId']).'.md',
                            'edit' => 'https://github.com/appwrite/appwrite/edit/master' . $route->getLabel('sdk.description', ''),
                            'rate-limit' => $route->getLabel('abuse-limit', 0),
                            'rate-time' => $route->getLabel('abuse-time', 3600),
                        ];
                    }

                    if ((!empty($scope) && 'public' != $scope)) {
                        $temp['security'][] = $route->getLabel('sdk.security', $security[$platform]);
                    }

                    $requestBody = [
                        'content' => [
                            'application/x-www-form-urlencoded' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [],
                                ],
                                'required' => [],
                            ],
                        ],
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
                                $node['x-example'] = '['.strtoupper(fromCamelCase($node['name'])).']';
                                break;
                            case 'Database\Validator\UID':
                                $node['type'] = 'string';
                                $node['x-example'] = '['.strtoupper(fromCamelCase($node['name'])).']';
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
                                $consumes = ['multipart/form-data'];
                                $node['type'] = 'file';
                                break;
                            case 'Utopia\Validator\ArrayList':
                                $node['type'] = 'array';
                                $node['collectionFormat'] = 'multi';
                                $node['items'] = [
                                    'type' => 'string',
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
                                $node['x-example'] = $validator->getMin();
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

                        if ($param['optional'] && !is_null($param['default'])) { // Param has default value
                            $node['default'] = $param['default'];
                        }

                        if (false !== strpos($url, ':'.$name)) { // Param is in URL path
                            $node['in'] = 'path';
                            $temp['parameters'][] = $node;
                        } elseif ($key == 'GET') { // Param is in query
                            $node['in'] = 'query';
                            $temp['parameters'][] = $node;
                        } else { // Param is in payload
                            $node['in'] = 'formData';
                            $temp['parameters'][] = $node;
                            $requestBody['content']['application/x-www-form-urlencoded']['schema']['properties'][] = $node;

                            if (!$param['optional']) {
                                $requestBody['content']['application/x-www-form-urlencoded']['required'][] = $name;
                            }
                        }

                        $url = str_replace(':'.$name, '{'.$name.'}', $url);
                    }

                    $temp['consumes'] = $consumes;

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

if (array_key_exists($service, $services)) { /** @noinspection PhpIncludeInspection */
    include_once $services[$service]['controller'];
    $name = APP_NAME.' '.ucfirst($services[$service]['name']);
} else {
    /** @noinspection PhpIncludeInspection */
    include_once $services['/']['controller'];
}

if (extension_loaded('newrelic')) {
    $route = $utopia->match($request);
    $url = (!empty($route)) ? $route->getURL() : '/error';

    newrelic_set_appname($name);
    newrelic_name_transaction($request->getServer('REQUEST_METHOD', 'UNKNOWN').': '.$url);
}

$utopia->run($request, $response);
