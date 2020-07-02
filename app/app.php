<?php

require_once __DIR__.'/init.php';

use Utopia\App;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\View;
use Utopia\Exception;
use Utopia\Config\Config;
use Utopia\Domains\Domain;
use Appwrite\Auth\Auth;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Network\Validator\Origin;

Config::setParam('domain', 'localhost');
Config::setParam('domainVerification', false);
Config::setParam('cookieDomain', 'localhost');
Config::setParam('cookieSamesite', Response::COOKIE_SAMESITE_NONE);

// // Set project mail
// $register->get('smtp')
//     ->setFrom(
//         App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM),
//         ($project->getId() === 'console')
//             ? \urldecode(App::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME.' Server'))
//             : \sprintf(Locale::getText('account.emails.team'), $project->getAttribute('name')
//         )
//     );

App::init(function ($utopia, $request, $response, $console, $project, $user, $locale, $webhooks, $audits, $usage, $clients) {
    Authorization::$roles = ['*'];
    
    /** @var Utopia\Request $request */
    /** @var Utopia\Response $response */
    /** @var Appwrite\Database\Document $console */
    /** @var Appwrite\Database\Document $project */
    /** @var Appwrite\Database\Document $user */
    /** @var Utopia\Locale\Locale $locale */
    /** @var Appwrite\Event\Event $webhook */
    /** @var Appwrite\Event\Event $audit */
    /** @var Appwrite\Event\Event $usage */
    /** @var Appwrite\Event\Event $mail */
    /** @var Appwrite\Event\Event $deletes */
    /** @var bool $mode */
    /** @var array $clients */

    $localeParam = (string)$request->getParam('locale', $request->getHeader('X-Appwrite-Locale', ''));

    if (\in_array($localeParam, Config::getParam('locale-codes'))) {
        $locale->setDefault($localeParam);
    };

    $route = $utopia->match($request);

    if(!empty($route->getLabel('sdk.platform', [])) && empty($project->getId()) && ($route->getLabel('scope', '') !== 'public')) {
        throw new Exception('Missing or unknown project ID', 400);
    }

    $console->setAttribute('platforms', [ // Allways allow current host
        '$collection' => Database::SYSTEM_COLLECTION_PLATFORMS,
        'name' => 'Current Host',
        'type' => 'web',
        'hostname' => \parse_url('https://'.$request->getServer('HTTP_HOST'), PHP_URL_HOST),
    ], Document::SET_TYPE_APPEND);

    $referrer = $request->getServer('HTTP_REFERER', '');
    $origin = \parse_url($request->getServer('HTTP_ORIGIN', $referrer), PHP_URL_HOST);
    $protocol = \parse_url($request->getServer('HTTP_ORIGIN', $referrer), PHP_URL_SCHEME);
    $port = \parse_url($request->getServer('HTTP_ORIGIN', $referrer), PHP_URL_PORT);

    $refDomain = $protocol.'://'.((\in_array($origin, $clients))
        ? $origin : 'localhost') . (!empty($port) ? ':'.$port : '');

    $selfDomain = new Domain(Config::getParam('hostname'));
    $endDomain = new Domain($origin);

    Config::setParam('domain', $request->getServer('HTTP_HOST', ''));

    Config::setParam('domainVerification',
        ($selfDomain->getRegisterable() === $endDomain->getRegisterable()) &&
            $endDomain->getRegisterable() !== '');
        
    Config::setParam('cookieDomain', (
        $request->getServer('HTTP_HOST', null) === 'localhost' ||
        $request->getServer('HTTP_HOST', null) === 'localhost:'.$request->getPort() ||
        (\filter_var($request->getHostname(), FILTER_VALIDATE_IP) !== false)
    )
        ? null
        : '.'.$request->getHostname()
    );
    /*
     * Security Headers
     *
     * As recommended at:
     * @see https://www.owasp.org/index.php/List_of_useful_HTTP_headers
     */
    if (App::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'enabled') { // Force HTTPS
        if($request->getProtocol() !== 'https') {
           return $response->redirect('https://' . Config::getParam('domain').$request->getServer('REQUEST_URI'));
        }

        $response->addHeader('Strict-Transport-Security', 'max-age='.(60 * 60 * 24 * 126)); // 126 days
    }    

    $response
        ->addHeader('Server', 'Appwrite')
        ->addHeader('X-XSS-Protection', '1; mode=block; report=/v1/xss?url='.\urlencode($request->getServer('REQUEST_URI')))
        //->addHeader('X-Frame-Options', ($refDomain == 'http://localhost') ? 'SAMEORIGIN' : 'ALLOW-FROM ' . $refDomain)
        ->addHeader('X-Content-Type-Options', 'nosniff')
        ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
        ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-SDK-Version, Cache-Control, Expires, Pragma')
        ->addHeader('Access-Control-Expose-Headers', 'X-Fallback-Cookies')
        ->addHeader('Access-Control-Allow-Origin', $refDomain)
        ->addHeader('Access-Control-Allow-Credentials', 'true')
    ;

    /*
     * Validate Client Domain - Check to avoid CSRF attack
     *  Adding Appwrite API domains to allow XDOMAIN communication
     *  Skip this check for non-web platforms which are not requiredto send an origin header
     */
    $origin = $request->getServer('HTTP_ORIGIN', $request->getServer('HTTP_REFERER', ''));
    $originValidator = new Origin(\array_merge($project->getAttribute('platforms', []), $console->getAttribute('platforms', [])));

    if(!$originValidator->isValid($origin)
        && \in_array($request->getMethod(), [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_PATCH, Request::METHOD_DELETE])
        && $route->getLabel('origin', false) !== '*'
        && empty($request->getHeader('X-Appwrite-Key', ''))) {
            throw new Exception($originValidator->getDescription(), 403);
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

    $roles = Config::getParam('roles', []);
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
        $scopes = \array_merge($roles[$role]['scopes'], $key->getAttribute('scopes', []));

        Authorization::setDefaultStatus(false);  // Cancel security segmentation for API keys.
    }

    Authorization::setRole('user:'.$user->getId());
    Authorization::setRole('role:'.$role);

    \array_map(function ($node) {
        if (isset($node['teamId']) && isset($node['roles'])) {
            Authorization::setRole('team:'.$node['teamId']);

            foreach ($node['roles'] as $nodeRole) { // Set all team roles
                Authorization::setRole('team:'.$node['teamId'].'/'.$nodeRole);
            }
        }
    }, $user->getAttribute('memberships', []));

    // TDOO Check if user is god

    if (!\in_array($scope, $scopes)) {
        if (empty($project->getId()) || Database::SYSTEM_COLLECTION_PROJECTS !== $project->getCollection()) { // Check if permission is denied because project is missing
            throw new Exception('Project not found', 404);
        }
        
        throw new Exception($user->getAttribute('email', 'User').' (role: '.\strtolower($roles[$role]['label']).') missing scope ('.$scope.')', 401);
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
    $webhooks
        ->setParam('projectId', $project->getId())
        ->setParam('event', $route->getLabel('webhook', ''))
        ->setParam('payload', [])
    ;

    $audits
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
}, ['utopia', 'request', 'response', 'console', 'project', 'user', 'locale', 'webhook', 'audit', 'usage', 'clients']);

App::shutdown(function ($utopia, $response, $request, $webhook, $audit, $usage, $deletes, $mode, $project) {
    /*
     * Trigger events for background workers
     */
    if (!empty($webhook->getParam('event'))) {
        $webhook->trigger();
    }
    
    if (!empty($audit->getParam('event'))) {
        $audit->trigger();
    }
    
    if (!empty($deletes->getParam('document'))) {
        $deletes->trigger();
    }
    
    $route = $utopia->match($request);
    
    if($project->getId()
        && $mode !== APP_MODE_ADMIN
        && !empty($route->getLabel('sdk.namespace', null))) { // Don't calculate console usage and admin mode
        $usage
            ->setParam('request', $request->getSize() + $usage->getParam('storage'))
            ->setParam('response', $response->getSize())
            ->trigger()
        ;
    }
}, ['utopia', 'response', 'request', 'webhook', 'audit', 'usage', 'deletes', 'mode', 'project']);

App::options(function ($request, $response) {
    $origin = $request->getServer('HTTP_ORIGIN');

    $response
        ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
        ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-SDK-Version, Cache-Control, Expires, Pragma, X-Fallback-Cookies')
        ->addHeader('Access-Control-Expose-Headers', 'X-Fallback-Cookies')
        ->addHeader('Access-Control-Allow-Origin', $origin)
        ->addHeader('Access-Control-Allow-Credentials', 'true')
        ->send();
}, ['request', 'response']);

App::error(function ($error, $utopia, $request, $response, $project) {
    /** @var Exception $error */

    $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

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

    //$_SERVER = []; // Reset before reporting to error log to avoid keys being compromised

    $output = ((App::isDevelopment())) ? [
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

    $response->dynamic(new Document($output),
        $utopia->isDevelopment() ? Response::MODEL_ERROR_DEV : Response::MODEL_LOCALE);
}, ['error', 'utopia', 'request', 'response', 'project']);

App::get('/manifest.json')
    ->desc('Progressive app manifest file')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(function ($response) {
        /** @var Utopia\Response $response */

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
    }, ['response']);

App::get('/robots.txt')
    ->desc('Robots.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(function ($response) {
        $template = new View(__DIR__.'/views/general/robots.phtml');
        $response->text($template->render(false));
    }, ['response']);

App::get('/humans.txt')
    ->desc('Humans.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(function ($response) {
        $template = new View(__DIR__.'/views/general/humans.phtml');
        $response->text($template->render(false));
    }, ['response']);

App::get('/.well-known/acme-challenge')
    ->desc('SSL Verification')
    ->label('scope', 'public')
    ->label('docs', false)
    ->action(function ($request, $response) {
        $base = \realpath(APP_STORAGE_CERTIFICATES);
        $path = \str_replace('/.well-known/acme-challenge/', '', $request->getParam('q'));
        $absolute = \realpath($base.'/.well-known/acme-challenge/'.$path);

        if(!$base) {
            throw new Exception('Storage error', 500);
        }

        if(!$absolute) {
            throw new Exception('Unknown path', 404);
        }

        if(!\substr($absolute, 0, \strlen($base)) === $base) {
            throw new Exception('Invalid path', 401);
        }

        if(!\file_exists($absolute)) {
            throw new Exception('Unknown path', 404);
        }

        $content = @\file_get_contents($absolute);

        if(!$content) {
            throw new Exception('Failed to get contents', 500);
        }

        $response->text($content);
    }, ['request', 'response']);

include_once __DIR__ . '/controllers/shared/api.php';
include_once __DIR__ . '/controllers/shared/web.php';

foreach(Config::getParam('services', []) as $service) {
    include_once $service['controller'];
}