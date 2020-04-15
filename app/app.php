<?php

// Init
require_once __DIR__.'/init.php';

global $utopia, $request, $response, $register, $consoleDB, $project, $service;

use Utopia\App;
use Utopia\Request;
use Utopia\View;
use Utopia\Exception;
use Utopia\Config\Config;
use Utopia\Domains\Domain;
use Appwrite\Auth\Auth;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Event\Event;

/*
 * Configuration files
 */
$roles = include __DIR__.'/config/roles.php'; // User roles and scopes
$services = include __DIR__.'/config/services.php'; // List of services

$webhook = new Event('v1-webhooks', 'WebhooksV1');
$audit = new Event('v1-audits', 'AuditsV1');
$usage = new Event('v1-usage', 'UsageV1');

/**
 * Get All verified client URLs for both console and current projects
 * + Filter for duplicated entries
 */
$clientsConsole = array_map(function ($node) {
        return $node['hostname'];
    }, array_filter($console->getAttribute('platforms', []), function ($node) {
        if (isset($node['type']) && $node['type'] === 'web' && isset($node['hostname']) && !empty($node['hostname'])) {
            return true;
        }

        return false;
    }));

$clients = array_unique(array_merge($clientsConsole, array_map(function ($node) {
        return $node['hostname'];
    }, array_filter($project->getAttribute('platforms', []), function ($node) {
        if (isset($node['type']) && $node['type'] === 'web' && isset($node['hostname']) && !empty($node['hostname'])) {
            return true;
        }

        return false;
    }))));

$utopia->init(function () use ($utopia, $request, $response, &$user, $project, $roles, $webhook, $audit, $usage, $clients) {
    
    $route = $utopia->match($request);

    if(!empty($route->getLabel('sdk.platform', [])) && empty($project->getId())) {
        throw new Exception('Missing or unknown project ID', 400);
    }

    $referrer = $request->getServer('HTTP_REFERER', '');
    $origin = parse_url($request->getServer('HTTP_ORIGIN', $referrer), PHP_URL_HOST);
    $protocol = parse_url($request->getServer('HTTP_ORIGIN', $referrer), PHP_URL_SCHEME);
    $port = parse_url($request->getServer('HTTP_ORIGIN', $referrer), PHP_URL_PORT);

    $refDomain = $protocol.'://'.((in_array($origin, $clients))
        ? $origin : 'localhost') . (!empty($port) ? ':'.$port : '');

    $selfDomain = new Domain(Config::getParam('domain'));
    $endDomain = new Domain($origin);

    Config::setParam('domainVerification',
        ($selfDomain->getRegisterable() === $endDomain->getRegisterable()));
        
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
        ->addHeader('Access-Control-Expose-Headers', 'X-Fallback-Cookies')
        ->addHeader('Access-Control-Allow-Origin', $refDomain)
        ->addHeader('Access-Control-Allow-Credentials', 'true')
    ;

    /*
     * Validate Client Domain - Check to avoid CSRF attack
     *  Adding Appwrite API domains to allow XDOMAIN communication
     *  Skip this check for non-web platforms which are not requiredto send an origin header
     */
    $origin = parse_url($request->getServer('HTTP_ORIGIN', $request->getServer('HTTP_REFERER', '')), PHP_URL_HOST);
    
    if (!empty($origin)
        && !in_array($origin, $clients)
        && in_array($request->getMethod(), [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_PATCH, Request::METHOD_DELETE])
        && empty($request->getHeader('X-Appwrite-Key', ''))
    ) {
        throw new Exception('Access from this client host is forbidden', 403);
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
            '$id' => 0,
            'status' => Auth::USER_STATUS_ACTIVATED,
            'email' => 'app.'.$project->getId().'@service.'.Config::getParam('domain'),
            'password' => '',
            'name' => $project->getAttribute('name', 'Untitled'),
        ]);

        $role = Auth::USER_ROLE_APP;
        $scopes = array_merge($roles[$role]['scopes'], $key->getAttribute('scopes', []));

        Authorization::setDefaultStatus(false);  // Cancel security segmentation for API keys.
    }

    Authorization::setRole('user:'.$user->getId());
    Authorization::setRole('role:'.$role);

    array_map(function ($node) {
        if (isset($node['teamId']) && isset($node['roles'])) {
            Authorization::setRole('team:'.$node['teamId']);

            foreach ($node['roles'] as $nodeRole) { // Set all team roles
                Authorization::setRole('team:'.$node['teamId'].'/'.$nodeRole);
            }
        }
    }, $user->getAttribute('memberships', []));

    // TDOO Check if user is god

    if (!in_array($scope, $scopes)) {
        if (empty($project->getId()) || Database::SYSTEM_COLLECTION_PROJECTS !== $project->getCollection()) { // Check if permission is denied because project is missing
            throw new Exception('Project not found', 404);
        }
        
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
        ->setParam('projectId', $project->getId())
        ->setParam('event', $route->getLabel('webhook', ''))
        ->setParam('payload', [])
    ;

    $audit
        ->setParam('projectId', $project->getId())
        ->setParam('userId', $user->getId())
        ->setParam('event', '')
        ->setParam('resource', '')
        ->setParam('userAgent', $request->getServer('HTTP_USER_AGENT', ''))
        ->setParam('ip', $request->getIP())
        ->setParam('data', [])
    ;

    $usage
        ->setParam('projectId', $project->getId())
        ->setParam('url', $request->getServer('HTTP_HOST', '').$request->getServer('REQUEST_URI', ''))
        ->setParam('method', $request->getServer('REQUEST_METHOD', 'UNKNOWN'))
        ->setParam('request', 0)
        ->setParam('response', 0)
        ->setParam('storage', 0)
    ;
});

$utopia->shutdown(function () use ($response, $request, $webhook, $audit, $usage, $mode, $project, $utopia) {

    /*
     * Trigger Events for background jobs
     */
    if (!empty($webhook->getParam('event'))) {
        $webhook->trigger();
    }
    
    if (!empty($audit->getParam('event'))) {
        $audit->trigger();
    }
    
    $route = $utopia->match($request);

    if($project->getId()
        && $mode !== APP_MODE_ADMIN
        && !empty($route->getLabel('sdk.namespace', null))) { // Don't calculate console usage and admin mode
        $usage
            ->setParam('request', $request->getSize())
            ->setParam('response', $response->getSize())
            ->trigger()
        ;
    }
});

$utopia->options(function () use ($request, $response) {
    $origin = $request->getServer('HTTP_ORIGIN');

    $response
        ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
        ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-SDK-Version, X-Fallback-Cookies')
        ->addHeader('Access-Control-Expose-Headers', 'X-Fallback-Cookies')
        ->addHeader('Access-Control-Allow-Origin', $origin)
        ->addHeader('Access-Control-Allow-Credentials', 'true')
        ->send();
});

$utopia->error(function ($error /* @var $error Exception */) use ($request, $response, $utopia, $project) {
    $env = Config::getParam('env');
    $version = Config::getParam('version');

    switch ($error->getCode()) {
        case 400: // Error allowed publicly
        case 401: // Error allowed publicly
        case 402: // Error allowed publicly
        case 403: // Error allowed publicly
        case 404: // Error allowed publicly
        case 409: // Error allowed publicly
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
            $template = new View(__DIR__.'/views/general/robots.phtml');
            $response->text($template->render(false));
        }
    );

$utopia->get('/humans.txt')
    ->desc('Humans.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(
        function () use ($response) {
            $template = new View(__DIR__.'/views/general/humans.phtml');
            $response->text($template->render(false));
        }
    );

$utopia->get('/.well-known/acme-challenge')
    ->desc('SSL Verification')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(
        function () use ($request, $response) {
            $base = realpath(APP_STORAGE_CERTIFICATES);
            $path = str_replace('/.well-known/acme-challenge/', '', $request->getParam('q'));
            $absolute = realpath($base.'/.well-known/acme-challenge/'.$path);

            if(!$base) {
                throw new Exception('Storage error', 500);
            }

            if(!$absolute) {
                throw new Exception('Unknown path', 404);
            }

            if(!substr($absolute, 0, strlen($base)) === $base) {
                throw new Exception('Invalid path', 401);
            }

            if(!file_exists($absolute)) {
                throw new Exception('Unknown path', 404);
            }

            $content = @file_get_contents($absolute);

            if(!$content) {
                throw new Exception('Failed to get contents', 500);
            }

            $response->text($content);
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

$utopia->run($request, $response);