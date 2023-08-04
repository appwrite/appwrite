<?php

require_once __DIR__ . '/../init.php';

use Utopia\App;
use Utopia\Database\Role;
use Utopia\Locale\Locale;
use Utopia\Logger\Logger;
use Utopia\Logger\Log;
use Utopia\Logger\Log\User;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Cache\Cache;
use Utopia\Pools\Group;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\View;
use Appwrite\Extend\Exception as AppwriteException;
use Utopia\Config\Config;
use Utopia\Domains\Domain;
use Appwrite\Auth\Auth;
use Appwrite\Event\Certificate;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Response\Filters\V11 as ResponseV11;
use Appwrite\Utopia\Response\Filters\V12 as ResponseV12;
use Appwrite\Utopia\Response\Filters\V13 as ResponseV13;
use Appwrite\Utopia\Response\Filters\V14 as ResponseV14;
use Appwrite\Utopia\Response\Filters\V15 as ResponseV15;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Hostname;
use Appwrite\Utopia\Request\Filters\V12 as RequestV12;
use Appwrite\Utopia\Request\Filters\V13 as RequestV13;
use Appwrite\Utopia\Request\Filters\V14 as RequestV14;
use Appwrite\Utopia\Request\Filters\V15 as RequestV15;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

Config::setParam('domainVerification', false);
Config::setParam('cookieDomain', 'localhost');
Config::setParam('cookieSamesite', Response::COOKIE_SAMESITE_NONE);

function router(App $utopia, Database $dbForConsole, SwooleRequest $swooleRequest, Request $request, Response $response)
{
    $utopia->getRoute()->label('error', __DIR__ . '/../views/general/error.phtml');

    $host = $request->getHostname() ?? '';

    $route = Authorization::skip(
        fn() => $dbForConsole->find('rules', [
            Query::equal('domain', [$host]),
            Query::limit(1)
        ])
    )[0] ?? null;

    if ($route === null) {
        $mainDomain = App::getEnv('_APP_DOMAIN', '');

        if ($mainDomain === 'localhost') {
            throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Please configure domain environment variables before using Appwrite outside of localhost.');
        } else {
            throw new AppwriteException(AppwriteException::ROUTER_HOST_NOT_FOUND);
        }
    }

    $projectId = $route->getAttribute('projectId');
    $project = Authorization::skip(
        fn() => $dbForConsole->getDocument('projects', $projectId)
    );
    if (array_key_exists('proxy', $project->getAttribute('services', []))) {
        $status = $project->getAttribute('services', [])['proxy'];
        if (!$status) {
            throw new AppwriteException(AppwriteException::GENERAL_SERVICE_DISABLED);
        }
    }

    // Skip Appwrite Router for ACME challenge. Nessessary for certificate generation
    $path = ($swooleRequest->server['request_uri'] ?? '');
    if (\str_starts_with($path, '/.well-known/acme-challenge')) {
        return false;
    }

    $type = $route->getAttribute('resourceType');

    if ($type === 'function') {
        $functionId = $route->getAttribute('resourceId');
        $projectId = $route->getAttribute('projectId');

        $path = ($swooleRequest->server['request_uri'] ?? '');
        $query = ($swooleRequest->server['query_string'] ?? '');
        if (!empty($query)) {
            $path .= '?' . $query;
        }

        $body = \json_encode([
            'async' => false,
            'body' => $swooleRequest->getContent() ?? '',
            'method' => $swooleRequest->server['request_method'],
            'path' => $path,
            'headers' => $swooleRequest->header
        ]);

        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . \strlen($body),
            'X-Appwrite-Project: ' . $projectId
        ];

        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, "http://localhost/v1/functions/{$functionId}/executions");
        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // \curl_setopt($ch, CURLOPT_HEADER, true);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $executionResponse = \curl_exec($ch);
        $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        $errNo = \curl_errno($ch);

        \curl_close($ch);

        if ($errNo !== 0) {
            throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, "Internal error: " . $error);
        }

        if ($statusCode >= 400) {
            $error = \json_decode($executionResponse, true)['message'];
            throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, "Execution error: " . $error);
        }

        $execution = \json_decode($executionResponse, true);

        foreach ($execution['responseHeaders'] as $header) {
            $response->setHeader($header['key'], $header['value']);
        }

        $body = $execution['responseBody'] ?? '';

        if (($execution['responseHeaders']['x-open-runtimes-encoding'] ?? '') === 'base64') {
            $body = \base64_decode($body);
        }

        $response->setStatusCode($execution['responseStatusCode'] ?? 200)->send($body);
        return true;
    } elseif ($type === 'api') {
        return false;
    } else {
        throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Unknown resource type ' . $type);
    }

    return false;
}

App::init()
    ->inject('utopia')
    ->inject('swooleRequest')
    ->inject('request')
    ->inject('response')
    ->inject('console')
    ->inject('project')
    ->inject('dbForConsole')
    ->inject('user')
    ->inject('locale')
    ->inject('clients')
    ->inject('servers')
    ->inject('pools')
    ->inject('cache')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Document $console, Document $project, Database $dbForConsole, Document $user, Locale $locale, array $clients, array $servers, Group $pools, Cache $cache) {
        /*
        * Appwrite Router
        */

        $host = $request->getHostname() ?? '';
        $mainDomain = App::getEnv('_APP_DOMAIN', '');
        // Only run Router when external domain
        if ($host !== $mainDomain && $host !== 'localhost') {
            if (router($utopia, $dbForConsole, $swooleRequest, $request, $response)) {
                return;
            }
        }

        /*
        * Request format
        */
        $route = $utopia->getRoute();
        Request::setRoute($route);

        if ($route === null) {
            return $response->setStatusCode(404)->send("Not Found");
        }

        $requestFormat = $request->getHeader('x-appwrite-response-format', App::getEnv('_APP_SYSTEM_RESPONSE_FORMAT', ''));
        if ($requestFormat) {
            switch ($requestFormat) {
                case version_compare($requestFormat, '0.12.0', '<'):
                    Request::setFilter(new RequestV12());
                    break;
                case version_compare($requestFormat, '0.13.0', '<'):
                    Request::setFilter(new RequestV13());
                    break;
                case version_compare($requestFormat, '0.14.0', '<'):
                    Request::setFilter(new RequestV14());
                    break;
                case version_compare($requestFormat, '0.15.3', '<'):
                    Request::setFilter(new RequestV15());
                    break;
                default:
                    Request::setFilter(null);
            }
        } else {
            Request::setFilter(null);
        }

        $localeParam = (string) $request->getParam('locale', $request->getHeader('x-appwrite-locale', ''));
        if (\in_array($localeParam, Config::getParam('locale-codes'))) {
            $locale->setDefault($localeParam);
        }

        if ($project->isEmpty()) {
            throw new AppwriteException(AppwriteException::PROJECT_NOT_FOUND);
        }

        if (!empty($route->getLabel('sdk.auth', [])) && $project->isEmpty() && ($route->getLabel('scope', '') !== 'public')) {
            throw new AppwriteException(AppwriteException::PROJECT_UNKNOWN);
        }

        $referrer = $request->getReferer();
        $origin = \parse_url($request->getOrigin($referrer), PHP_URL_HOST);
        $protocol = \parse_url($request->getOrigin($referrer), PHP_URL_SCHEME);
        $port = \parse_url($request->getOrigin($referrer), PHP_URL_PORT);

        $refDomainOrigin = 'localhost';
        $validator = new Hostname($clients);
        if ($validator->isValid($origin)) {
            $refDomainOrigin = $origin;
        }

        $refDomain = (!empty($protocol) ? $protocol : $request->getProtocol()) . '://' . $refDomainOrigin . (!empty($port) ? ':' . $port : '');

        $refDomain = (!$route->getLabel('origin', false))  // This route is publicly accessible
            ? $refDomain
            : (!empty($protocol) ? $protocol : $request->getProtocol()) . '://' . $origin . (!empty($port) ? ':' . $port : '');

        $selfDomain = new Domain($request->getHostname());
        $endDomain = new Domain((string)$origin);

        Config::setParam(
            'domainVerification',
            ($selfDomain->getRegisterable() === $endDomain->getRegisterable()) &&
            $endDomain->getRegisterable() !== ''
        );

        Config::setParam('cookieDomain', (
            $request->getHostname() === 'localhost' ||
            $request->getHostname() === 'localhost:' . $request->getPort() ||
            (\filter_var($request->getHostname(), FILTER_VALIDATE_IP) !== false)
        )
            ? null
            : '.' . $request->getHostname());

        /*
        * Response format
        */
        $responseFormat = $request->getHeader('x-appwrite-response-format', App::getEnv('_APP_SYSTEM_RESPONSE_FORMAT', ''));
        if ($responseFormat) {
            switch ($responseFormat) {
                case version_compare($responseFormat, '0.11.2', '<='):
                    Response::setFilter(new ResponseV11());
                    break;
                case version_compare($responseFormat, '0.12.4', '<='):
                    Response::setFilter(new ResponseV12());
                    break;
                case version_compare($responseFormat, '0.13.4', '<='):
                    Response::setFilter(new ResponseV13());
                    break;
                case version_compare($responseFormat, '0.14.0', '<='):
                    Response::setFilter(new ResponseV14());
                    break;
                case version_compare($responseFormat, '0.15.3', '<='):
                    Response::setFilter(new ResponseV15());
                    break;
                default:
                    Response::setFilter(null);
            }
        } else {
            Response::setFilter(null);
        }

        /*
        * Security Headers
        *
        * As recommended at:
        * @see https://www.owasp.org/index.php/List_of_useful_HTTP_headers
        */
        if (App::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'enabled') { // Force HTTPS
            if ($request->getProtocol() !== 'https' && ($swooleRequest->header['host'] ?? '') !== 'localhost') { // Localhost allowed for proxy
                if ($request->getMethod() !== Request::METHOD_GET) {
                    throw new AppwriteException(AppwriteException::GENERAL_PROTOCOL_UNSUPPORTED, 'Method unsupported over HTTP.');
                }

                return $response->redirect('https://' . $request->getHostname() . $request->getURI());
            }
        }

        if ($request->getProtocol() === 'https') {
            $response->addHeader('Strict-Transport-Security', 'max-age=' . (60 * 60 * 24 * 126)); // 126 days
        }

        $response
            ->addHeader('Server', 'Appwrite')
            ->addHeader('X-Content-Type-Options', 'nosniff')
            ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
            ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-Appwrite-JWT, X-Appwrite-Response-Format, X-SDK-Version, X-SDK-Name, X-SDK-Language, X-SDK-Platform, X-Appwrite-ID, Content-Range, Range, Cache-Control, Expires, Pragma')
            ->addHeader('Access-Control-Expose-Headers', 'X-Fallback-Cookies')
            ->addHeader('Access-Control-Allow-Origin', $refDomain)
            ->addHeader('Access-Control-Allow-Credentials', 'true')
        ;

        /*
        * Validate Client Domain - Check to avoid CSRF attack
        *  Adding Appwrite API domains to allow XDOMAIN communication
        *  Skip this check for non-web platforms which are not required to send an origin header
        */
        $origin = $request->getOrigin($request->getReferer(''));
        $originValidator = new Origin(\array_merge($project->getAttribute('platforms', []), $console->getAttribute('platforms', [])));

        if (
            !$originValidator->isValid($origin)
            && \in_array($request->getMethod(), [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_PATCH, Request::METHOD_DELETE])
            && $route->getLabel('origin', false) !== '*'
            && empty($request->getHeader('x-appwrite-key', ''))
        ) {
            throw new AppwriteException(AppwriteException::GENERAL_UNKNOWN_ORIGIN, $originValidator->getDescription());
        }

        /*
        * ACL Check
        */
        $role = ($user->isEmpty())
            ? Role::guests()->toString()
            : Role::users()->toString();

        // Add user roles
        $memberships = $user->find('teamId', $project->getAttribute('teamId', null), 'memberships');

        if ($memberships) {
            foreach ($memberships->getAttribute('roles', []) as $memberRole) {
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

        $authKey = $request->getHeader('x-appwrite-key', '');

        if (!empty($authKey)) { // API Key authentication
            // Check if given key match project API keys
            $key = $project->find('secret', $authKey, 'keys');

            /*
            * Try app auth when we have project key and no user
            *  Mock user to app and grant API key scopes in addition to default app scopes
            */
            if ($key && $user->isEmpty()) {
                $user = new Document([
                    '$id' => '',
                    'status' => true,
                    'email' => 'app.' . $project->getId() . '@service.' . $request->getHostname(),
                    'password' => '',
                    'name' => $project->getAttribute('name', 'Untitled'),
                ]);

                $role = Auth::USER_ROLE_APPS;
                $scopes = \array_merge($roles[$role]['scopes'], $key->getAttribute('scopes', []));

                $expire = $key->getAttribute('expire');
                if (!empty($expire) && $expire < DateTime::formatTz(DateTime::now())) {
                    throw new AppwriteException(AppwriteException:: PROJECT_KEY_EXPIRED);
                }

                Authorization::setRole(Auth::USER_ROLE_APPS);
                Authorization::setDefaultStatus(false);  // Cancel security segmentation for API keys.

                $accessedAt = $key->getAttribute('accessedAt', '');
                if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_KEY_ACCCESS)) > $accessedAt) {
                    $key->setAttribute('accessedAt', DateTime::now());
                    $dbForConsole->updateDocument('keys', $key->getId(), $key);
                    $dbForConsole->deleteCachedDocument('projects', $project->getId());
                }

                $sdkValidator = new WhiteList($servers, true);
                $sdk = $request->getHeader('x-sdk-name', 'UNKNOWN');
                if ($sdkValidator->isValid($sdk)) {
                    $sdks = $key->getAttribute('sdks', []);
                    if (!in_array($sdk, $sdks)) {
                        array_push($sdks, $sdk);
                        $key->setAttribute('sdks', $sdks);

                        /** Update access time as well */
                        $key->setAttribute('accessedAt', Datetime::now());
                        $dbForConsole->updateDocument('keys', $key->getId(), $key);
                        $dbForConsole->deleteCachedDocument('projects', $project->getId());
                    }
                }
            }
        }

        Authorization::setRole($role);

        foreach (Auth::getRoles($user) as $authRole) {
            Authorization::setRole($authRole);
        }

        $service = $route->getLabel('sdk.namespace', '');
        if (!empty($service)) {
            if (
                array_key_exists($service, $project->getAttribute('services', []))
                && !$project->getAttribute('services', [])[$service]
                && !(Auth::isPrivilegedUser(Authorization::getRoles()) || Auth::isAppUser(Authorization::getRoles()))
            ) {
                throw new AppwriteException(AppwriteException::GENERAL_SERVICE_DISABLED);
            }
        }

        if (!\in_array($scope, $scopes)) {
            if ($project->isEmpty()) { // Check if permission is denied because project is missing
                throw new AppwriteException(AppwriteException::PROJECT_NOT_FOUND);
            }

            throw new AppwriteException(AppwriteException::GENERAL_UNAUTHORIZED_SCOPE, $user->getAttribute('email', 'User') . ' (role: ' . \strtolower($roles[$role]['label']) . ') missing scope (' . $scope . ')');
        }

        if (false === $user->getAttribute('status')) { // Account is blocked
            throw new AppwriteException(AppwriteException::USER_BLOCKED);
        }

        if ($user->getAttribute('reset')) {
            throw new AppwriteException(AppwriteException::USER_PASSWORD_RESET_REQUIRED);
        }
    });

App::options()
    ->inject('utopia')
    ->inject('swooleRequest')
    ->inject('request')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Database $dbForConsole) {
        /*
        * Appwrite Router
        */
        $host = $request->getHostname() ?? '';
        $mainDomain = App::getEnv('_APP_DOMAIN', '');
        // Only run Router when external domain
        if ($host !== $mainDomain && $host !== 'localhost') {
            if (router($utopia, $dbForConsole, $swooleRequest, $request, $response)) {
                return;
            }
        }

        $origin = $request->getOrigin();

        $response
            ->addHeader('Server', 'Appwrite')
            ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
            ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-Appwrite-JWT, X-Appwrite-Response-Format, X-SDK-Version, X-SDK-Name, X-SDK-Language, X-SDK-Platform, X-Appwrite-ID, Content-Range, Range, Cache-Control, Expires, Pragma, X-Fallback-Cookies')
            ->addHeader('Access-Control-Expose-Headers', 'X-Fallback-Cookies')
            ->addHeader('Access-Control-Allow-Origin', $origin)
            ->addHeader('Access-Control-Allow-Credentials', 'true')
            ->noContent();
    });

App::error()
    ->inject('error')
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('logger')
    ->inject('loggerBreadcrumbs')
    ->action(function (Throwable $error, App $utopia, Request $request, Response $response, Document $project, ?Logger $logger, array $loggerBreadcrumbs) {

        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');
        $route = $utopia->getRoute();

        if ($logger) {
            if ($error->getCode() >= 500 || $error->getCode() === 0) {
                try {
                    /** @var Utopia\Database\Document $user */
                    $user = $utopia->getResource('user');
                } catch (\Throwable $th) {
                    // All good, user is optional information for logger
                }

                $log = new Utopia\Logger\Log();

                if (isset($user) && !$user->isEmpty()) {
                    $log->setUser(new User($user->getId()));
                }

                $log->setNamespace("http");
                $log->setServer(\gethostname());
                $log->setVersion($version);
                $log->setType(Log::TYPE_ERROR);
                $log->setMessage($error->getMessage());

                $log->addTag('method', $route->getMethod());
                $log->addTag('url', $route->getPath());
                $log->addTag('verboseType', get_class($error));
                $log->addTag('code', $error->getCode());
                $log->addTag('projectId', $project->getId());
                $log->addTag('hostname', $request->getHostname());
                $log->addTag('locale', (string)$request->getParam('locale', $request->getHeader('x-appwrite-locale', '')));

                $log->addExtra('file', $error->getFile());
                $log->addExtra('line', $error->getLine());
                $log->addExtra('trace', $error->getTraceAsString());
                $log->addExtra('detailedTrace', $error->getTrace());
                $log->addExtra('roles', Authorization::$roles);

                $action = $route->getLabel("sdk.namespace", "UNKNOWN_NAMESPACE") . '.' . $route->getLabel("sdk.method", "UNKNOWN_METHOD");
                $log->setAction($action);

                $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
                $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

                foreach ($loggerBreadcrumbs as $loggerBreadcrumb) {
                    $log->addBreadcrumb($loggerBreadcrumb);
                }

                $responseCode = $logger->addLog($log);
                Console::info('Log pushed with status code: ' . $responseCode);
            }
        }

        $code = $error->getCode();
        $message = $error->getMessage();
        $file = $error->getFile();
        $line = $error->getLine();
        $trace = $error->getTrace();

        if (php_sapi_name() === 'cli') {
            Console::error('[Error] Timestamp: ' . date('c', time()));

            if ($route) {
                Console::error('[Error] Method: ' . $route->getMethod());
                Console::error('[Error] URL: ' . $route->getPath());
            }

            Console::error('[Error] Type: ' . get_class($error));
            Console::error('[Error] Message: ' . $message);
            Console::error('[Error] File: ' . $file);
            Console::error('[Error] Line: ' . $line);
        }

        /** Handle Utopia Errors */
        if ($error instanceof Utopia\Exception) {
            $error = new AppwriteException(AppwriteException::GENERAL_UNKNOWN, $message, $code, $error);
            switch ($code) {
                case 400:
                    $error->setType(AppwriteException::GENERAL_ARGUMENT_INVALID);
                    break;
                case 404:
                    $error->setType(AppwriteException::GENERAL_ROUTE_NOT_FOUND);
                    break;
            }
        }

        /** Wrap all exceptions inside Appwrite\Extend\Exception */
        if (!($error instanceof AppwriteException)) {
            $error = new AppwriteException(AppwriteException::GENERAL_UNKNOWN, $message, $code, $error);
        }

        switch ($code) { // Don't show 500 errors!
            case 400: // Error allowed publicly
            case 401: // Error allowed publicly
            case 402: // Error allowed publicly
            case 403: // Error allowed publicly
            case 404: // Error allowed publicly
            case 409: // Error allowed publicly
            case 412: // Error allowed publicly
            case 416: // Error allowed publicly
            case 429: // Error allowed publicly
            case 501: // Error allowed publicly
            case 503: // Error allowed publicly
                break;
            default:
                $code = 500; // All other errors get the generic 500 server error status code
                $message = 'Server Error';
        }

        //$_SERVER = []; // Reset before reporting to error log to avoid keys being compromised

        $type = $error->getType();

        $output = ((App::isDevelopment())) ? [
            'message' => $message,
            'code' => $code,
            'file' => $file,
            'line' => $line,
            'trace' => $trace,
            'version' => $version,
            'type' => $type,
        ] : [
            'message' => $message,
            'code' => $code,
            'version' => $version,
            'type' => $type,
        ];

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode($code)
        ;

        $template = ($route) ? $route->getLabel('error', null) : null;

        if ($template) {
            $layout = new View($template);

            $layout
                ->setParam('title', $project->getAttribute('name') . ' - Error')
                ->setParam('development', App::isDevelopment())
                ->setParam('projectName', $project->getAttribute('name'))
                ->setParam('projectURL', $project->getAttribute('url'))
                ->setParam('message', $error->getMessage())
                ->setParam('code', $code)
                ->setParam('trace', $trace)
            ;

            $response->html($layout->render());
        }

        $response->dynamic(
            new Document($output),
            $utopia->isDevelopment() ? Response::MODEL_ERROR_DEV : Response::MODEL_ERROR
        );
    });

App::get('/robots.txt')
    ->desc('Robots.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function (Response $response) {
        $template = new View(__DIR__ . '/../views/general/robots.phtml');
        $response->text($template->render(false));
    });

App::get('/humans.txt')
    ->desc('Humans.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function (Response $response) {
        $template = new View(__DIR__ . '/../views/general/humans.phtml');
        $response->text($template->render(false));
    });

App::get('/.well-known/acme-challenge')
    ->desc('SSL Verification')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('request')
    ->inject('response')
    ->action(function (Request $request, Response $response) {
        $uriChunks = \explode('/', $request->getURI());
        $token = $uriChunks[\count($uriChunks) - 1];

        $validator = new Text(100, [
            ...Text::NUMBERS,
            ...Text::ALPHABET_LOWER,
            ...Text::ALPHABET_UPPER,
            '-',
            '_'
        ]);

        if (!$validator->isValid($token) || \count($uriChunks) !== 4) {
            throw new AppwriteException(AppwriteException::GENERAL_ARGUMENT_INVALID, 'Invalid challenge token.');
        }

        $base = \realpath(APP_STORAGE_CERTIFICATES);
        $absolute = \realpath($base . '/.well-known/acme-challenge/' . $token);

        if (!$base) {
            throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Storage error');
        }

        if (!$absolute) {
            throw new AppwriteException(AppwriteException::GENERAL_ROUTE_NOT_FOUND, 'Unknown path');
        }

        if (!\substr($absolute, 0, \strlen($base)) === $base) {
            throw new AppwriteException(AppwriteException::GENERAL_UNAUTHORIZED_SCOPE, 'Invalid path');
        }

        if (!\file_exists($absolute)) {
            throw new AppwriteException(AppwriteException::GENERAL_ROUTE_NOT_FOUND, 'Unknown path');
        }

        $content = @\file_get_contents($absolute);

        if (!$content) {
            throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Failed to get contents');
        }

        $response->text($content);
    });

include_once __DIR__ . '/shared/api.php';

App::wildcard()
    ->action(function () {
        throw new AppwriteException(AppwriteException::GENERAL_ROUTE_NOT_FOUND);
    });

foreach (Config::getParam('services', []) as $service) {
    include_once $service['controller'];
}
