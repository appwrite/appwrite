<?php

use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Event\Usage;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Queue\Connections;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Request\Filters\V16 as RequestV16;
use Appwrite\Utopia\Request\Filters\V17 as RequestV17;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V16 as ResponseV16;
use Appwrite\Utopia\Response\Filters\V17 as ResponseV17;
use Appwrite\Utopia\View;
use Executor\Executor;
use MaxMind\Db\Reader;
use Swoole\Http\Request as SwooleRequest;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Dependency;
use Utopia\Domains\Domain;
use Utopia\Http\Http;
use Utopia\Http\Route;
use Utopia\Http\Validator\Hostname;
use Utopia\Http\Validator\Text;
use Utopia\Locale\Locale;
use Utopia\Logger\Log;
use Utopia\Logger\Log\User;
use Utopia\Logger\Logger;
use Utopia\Registry\Registry;
use Utopia\System\System;

Config::setParam('domainVerification', false);
Config::setParam('cookieDomain', 'localhost');
Config::setParam('cookieSamesite', Response::COOKIE_SAMESITE_NONE);

// function router(Http $utopia, Database $dbForConsole, callable $getProjectDB, Request $request, Response $response, Route $route, Event $queueForEvents, Usage $queueForUsage, Reader $geodb, Authorization $auth)
// {
//     $route?->label('error', __DIR__ . '/../views/general/error.phtml');

//     $host = $request->getHostname() ?? '';

//     $route = $auth->skip(
//         fn () => $dbForConsole->find('rules', [
//             Query::equal('domain', [$host]),
//             Query::limit(1)
//         ])
//     )[0] ?? null;

//     if ($route === null) {
//         if ($host === System::getEnv('_APP_DOMAIN_FUNCTIONS', '')) {
//             throw new AppwriteException(AppwriteException::GENERAL_ACCESS_FORBIDDEN, 'This domain cannot be used for security reasons. Please use any subdomain instead.');
//         }

//         if (\str_ends_with($host, System::getEnv('_APP_DOMAIN_FUNCTIONS', ''))) {
//             throw new AppwriteException(AppwriteException::GENERAL_ACCESS_FORBIDDEN, 'This domain is not connected to any Appwrite resource yet. Please configure custom domain or function domain to allow this request.');
//         }

//         if (System::getEnv('_APP_OPTIONS_ROUTER_PROTECTION', 'disabled') === 'enabled') {
//             if ($host !== 'localhost' && $host !== APP_HOSTNAME_INTERNAL) { // localhost allowed for proxy, APP_HOSTNAME_INTERNAL allowed for migrations
//                 throw new AppwriteException(AppwriteException::GENERAL_ACCESS_FORBIDDEN, 'Router protection does not allow accessing Appwrite over this domain. Please add it as custom domain to your project or disable _APP_OPTIONS_ROUTER_PROTECTION environment variable.');
//             }
//         }

//         // Act as API - no Proxy logic
//         $utopia->getRoute()?->label('error', '');
//         return false;
//     }

//     $projectId = $route->getAttribute('projectId');
//     $project = $auth->skip(
//         fn () => $dbForConsole->getDocument('projects', $projectId)
//     );
//     if (array_key_exists('proxy', $project->getAttribute('services', []))) {
//         $status = $project->getAttribute('services', [])['proxy'];
//         if (!$status) {
//             throw new AppwriteException(AppwriteException::GENERAL_SERVICE_DISABLED);
//         }
//     }

//     // Skip Appwrite Router for ACME challenge. Nessessary for certificate generation
//     $path = ($request->getURI() ?? '/');
//     if (\str_starts_with($path, '/.well-known/acme-challenge')) {
//         return false;
//     }

//     $type = $route->getAttribute('resourceType');

//     if ($type === 'function') {
//         if (System::getEnv('_APP_OPTIONS_FUNCTIONS_FORCE_HTTPS', 'disabled') === 'enabled') { // Force HTTPS
//             if ($request->getProtocol() !== 'https') {
//                 if ($request->getMethod() !== Request::METHOD_GET) {
//                     throw new AppwriteException(AppwriteException::GENERAL_PROTOCOL_UNSUPPORTED, 'Method unsupported over HTTP. Please use HTTPS instead.');
//                 }

//                 return $response->redirect('https://' . $request->getHostname() . $request->getURI());
//             }
//         }

//         $functionId = $route->getAttribute('resourceId');
//         $projectId = $route->getAttribute('projectId');

//         $path = ($swooleRequest->server['request_uri'] ?? '/');
//         $query = ($swooleRequest->server['query_string'] ?? '');
//         if (!empty($query)) {
//             $path .= '?' . $query;
//         }


//         $body = $swooleRequest->getContent() ?? '';
//         $method = $swooleRequest->server['request_method'];

//         $requestHeaders = $request->getHeaders();

//         $project = $auth->skip(fn () => $dbForConsole->getDocument('projects', $projectId));

//         $dbForProject = $getProjectDB($project);

//         $function = $auth->skip(fn () => $dbForProject->getDocument('functions', $functionId));

//         if ($function->isEmpty() || !$function->getAttribute('enabled')) {
//             throw new AppwriteException(AppwriteException::FUNCTION_NOT_FOUND);
//         }

//         $version = $function->getAttribute('version', 'v2');
//         $runtimes = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', []);

//         $runtime = (isset($runtimes[$function->getAttribute('runtime', '')])) ? $runtimes[$function->getAttribute('runtime', '')] : null;

//         if (\is_null($runtime)) {
//             throw new AppwriteException(AppwriteException::FUNCTION_RUNTIME_UNSUPPORTED, 'Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
//         }

//         $deployment = $auth->skip(fn () => $dbForProject->getDocument('deployments', $function->getAttribute('deployment', '')));

//         if ($deployment->getAttribute('resourceId') !== $function->getId()) {
//             throw new AppwriteException(AppwriteException::DEPLOYMENT_NOT_FOUND, 'Deployment not found. Create a deployment before trying to execute a function');
//         }

//         if ($deployment->isEmpty()) {
//             throw new AppwriteException(AppwriteException::DEPLOYMENT_NOT_FOUND, 'Deployment not found. Create a deployment before trying to execute a function');
//         }

//         /** Check if build has completed */
//         $build = $auth->skip(fn () => $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', '')));
//         if ($build->isEmpty()) {
//             throw new AppwriteException(AppwriteException::BUILD_NOT_FOUND);
//         }

//         if ($build->getAttribute('status') !== 'ready') {
//             throw new AppwriteException(AppwriteException::BUILD_NOT_READY);
//         }

//         $permissions = $function->getAttribute('execute');

//         if (!(\in_array('any', $permissions)) && (\in_array('guests', $permissions))) {
//             throw new AppwriteException(AppwriteException::USER_UNAUTHORIZED, 'To execute function using domain, execute permissions must include "any" or "guests"');
//         }

//         $headers = \array_merge([], $requestHeaders);
//         $headers['x-appwrite-trigger'] = 'http';
//         $headers['x-appwrite-user-id'] = '';
//         $headers['x-appwrite-user-jwt'] = '';
//         $headers['x-appwrite-country-code'] = '';
//         $headers['x-appwrite-continent-code'] = '';
//         $headers['x-appwrite-continent-eu'] = 'false';

//         $ip = $headers['x-real-ip'] ?? '';
//         if (!empty($ip)) {
//             $record = $geodb->get($ip);

//             if ($record) {
//                 $eu = Config::getParam('locale-eu');

//                 $headers['x-appwrite-country-code'] = $record['country']['iso_code'] ?? '';
//                 $headers['x-appwrite-continent-code'] = $record['continent']['code'] ?? '';
//                 $headers['x-appwrite-continent-eu'] = (\in_array($record['country']['iso_code'], $eu)) ? 'true' : 'false';
//             }
//         }

//         $headersFiltered = [];
//         foreach ($headers as $key => $value) {
//             if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_REQUEST)) {
//                 $headersFiltered[] = ['name' => $key, 'value' => $value];
//             }
//         }

//         $executionId = ID::unique();

//         $execution = new Document([
//             '$id' => $executionId,
//             '$permissions' => [],
//             'functionInternalId' => $function->getInternalId(),
//             'functionId' => $function->getId(),
//             'deploymentInternalId' => $deployment->getInternalId(),
//             'deploymentId' => $deployment->getId(),
//             'trigger' => 'http', // http / schedule / event
//             'status' =>  'processing', // waiting / processing / completed / failed
//             'responseStatusCode' => 0,
//             'responseHeaders' => [],
//             'requestPath' => $path,
//             'requestMethod' => $method,
//             'requestHeaders' => $headersFiltered,
//             'errors' => '',
//             'logs' => '',
//             'duration' => 0.0,
//             'search' => implode(' ', [$functionId, $executionId]),
//         ]);

//         $queueForEvents
//             ->setParam('functionId', $function->getId())
//             ->setParam('executionId', $execution->getId())
//             ->setContext('function', $function);

//         $durationStart = \microtime(true);

//         $vars = [];

//         // V2 vars
//         if ($version === 'v2') {
//             $vars = \array_merge($vars, [
//                 'APPWRITE_FUNCTION_TRIGGER' => $headers['x-appwrite-trigger'] ?? '',
//                 'APPWRITE_FUNCTION_DATA' => $body ?? '',
//                 'APPWRITE_FUNCTION_USER_ID' => $headers['x-appwrite-user-id'] ?? '',
//                 'APPWRITE_FUNCTION_JWT' => $headers['x-appwrite-user-jwt'] ?? ''
//             ]);
//         }

//         // Shared vars
//         foreach ($function->getAttribute('varsProject', []) as $var) {
//             $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
//         }

//         // Function vars
//         foreach ($function->getAttribute('vars', []) as $var) {
//             $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
//         }

//         // Appwrite vars
//         $vars = \array_merge($vars, [
//             'APPWRITE_FUNCTION_ID' => $functionId,
//             'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name'),
//             'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
//             'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
//             'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
//             'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
//         ]);

//         /** Execute function */
//         $executor = new Executor(System::getEnv('_APP_EXECUTOR_HOST'));
//         try {
//             $version = $function->getAttribute('version', 'v2');
//             $command = $runtime['startCommand'];
//             $command = $version === 'v2' ? '' : 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"';
//             $executionResponse = $executor->createExecution(
//                 projectId: $project->getId(),
//                 deploymentId: $deployment->getId(),
//                 body: \strlen($body) > 0 ? $body : null,
//                 variables: $vars,
//                 timeout: $function->getAttribute('timeout', 0),
//                 image: $runtime['image'],
//                 source: $build->getAttribute('path', ''),
//                 entrypoint: $deployment->getAttribute('entrypoint', ''),
//                 version: $version,
//                 path: $path,
//                 method: $method,
//                 headers: $headers,
//                 runtimeEntrypoint: $command,
//                 requestTimeout: 30
//             );

//             $headersFiltered = [];
//             foreach ($executionResponse['headers'] as $key => $value) {
//                 if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_RESPONSE)) {
//                     $headersFiltered[] = ['name' => $key, 'value' => $value];
//                 }
//             }

//             /** Update execution status */
//             $status = $executionResponse['statusCode'] >= 400 ? 'failed' : 'completed';
//             $execution->setAttribute('status', $status);
//             $execution->setAttribute('responseStatusCode', $executionResponse['statusCode']);
//             $execution->setAttribute('responseHeaders', $headersFiltered);
//             $execution->setAttribute('logs', $executionResponse['logs']);
//             $execution->setAttribute('errors', $executionResponse['errors']);
//             $execution->setAttribute('duration', $executionResponse['duration']);
//         } catch (\Throwable $th) {
//             $durationEnd = \microtime(true);

//             $execution
//                 ->setAttribute('duration', $durationEnd - $durationStart)
//                 ->setAttribute('status', 'failed')
//                 ->setAttribute('responseStatusCode', 500)
//                 ->setAttribute('errors', $th->getMessage() . '\nError Code: ' . $th->getCode());
//             Console::error($th->getMessage());
//         } finally {
//             $queueForUsage
//                 ->addMetric(METRIC_EXECUTIONS, 1)
//                 ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS), 1)
//                 ->addMetric(METRIC_EXECUTIONS_COMPUTE, (int)($execution->getAttribute('duration') * 1000)) // per project
//                 ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS_COMPUTE), (int)($execution->getAttribute('duration') * 1000)) // per function
//             ;
//         }

//         if ($function->getAttribute('logging')) {
//             /** @var Document $execution */
//             $execution = $auth->skip(fn () => $dbForProject->createDocument('executions', $execution));
//         }

//         $execution->setAttribute('logs', '');
//         $execution->setAttribute('errors', '');

//         $headers = [];
//         foreach (($executionResponse['headers'] ?? []) as $key => $value) {
//             $headers[] = ['name' => $key, 'value' => $value];
//         }

//         $execution->setAttribute('responseBody', $executionResponse['body'] ?? '');
//         $execution->setAttribute('responseHeaders', $headers);

//         $body = $execution['responseBody'] ?? '';

//         $encodingKey = \array_search('x-open-runtimes-encoding', \array_column($execution['responseHeaders'], 'name'));
//         if ($encodingKey !== false) {
//             if (($execution['responseHeaders'][$encodingKey]['value'] ?? '') === 'base64') {
//                 $body = \base64_decode($body);
//             }
//         }

//         $contentType = 'text/plain';
//         foreach ($execution['responseHeaders'] as $header) {
//             if (\strtolower($header['name']) === 'content-type') {
//                 $contentType = $header['value'];
//             }

//             $response->setHeader($header['name'], $header['value']);
//         }

//         $response
//             ->setContentType($contentType)
//             ->setStatusCode($execution['responseStatusCode'] ?? 200)
//             ->send($body);

//         return true;
//     } elseif ($type === 'api') {
//         $utopia->getRoute()?->label('error', '');
//         return false;
//     } else {
//         throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Unknown resource type ' . $type);
//     }

//     $utopia->getRoute()?->label('error', '');
//     return false;
// }

Http::init()
    ->groups(['api', 'web'])
    ->inject('request')
    ->inject('response')
    ->inject('route')
    ->inject('console')
    ->inject('project')
    ->inject('dbForConsole')
    // ->inject('getProjectDB')
    ->inject('locale')
    ->inject('localeCodes')
    ->inject('clients')
    // ->inject('geodb')
    // ->inject('queueForUsage')
    // ->inject('queueForEvents')
    ->inject('queueForCertificates')
    ->inject('authorization')
    ->action(function (
        Request $request,
        Response $response,
        Route $route,
        Document $console,
        Document $project,
        Database $dbForConsole,
        Locale $locale, array $localeCodes,
        array $clients,
        /**
         * @disregard P1009 Undefined type
         */
        // Reader $geodb,
        // Usage $queueForUsage,
        // Event $queueForEvents,
        Certificate $queueForCertificates,
        Authorization $authorization) {
        /*
        * Appwrite Router
        */
        $host = $request->getHostname() ?? '';
        $mainDomain = System::getEnv('_APP_DOMAIN', '');
        // Only run Router when external domain
        // if ($host !== $mainDomain) {
        //     if (router($utopia, $dbForConsole, $getProjectDB, $request, $response, $queueForEvents, $queueForUsage, $geodb, $auth)) {
        //         return;
        //     }
        // }

        /*
        * Request format
        */
        //$route = $utopia->getRoute();
        //Request::setRoute($route);

        if ($route === null) {
            return $response->setStatusCode(404)->send('Not Found');
        }

        $requestFormat = $request->getHeader('x-appwrite-response-format', System::getEnv('_APP_SYSTEM_RESPONSE_FORMAT', ''));
        if ($requestFormat) {
            if (version_compare($requestFormat, '1.4.0', '<')) {
                $request->addFilter(new RequestV16());
            }
            if (version_compare($requestFormat, '1.5.0', '<')) {
                $request->addFilter(new RequestV17());
            }
        }

        $domain = $request->getHostname();
        $domains = Config::getParam('domains', []);
        if (!array_key_exists($domain, $domains)) {
            $domain = new Domain(!empty($domain) ? $domain : '');

            if (empty($domain->get()) || !$domain->isKnown() || $domain->isTest()) {
                $domains[$domain->get()] = false;
                Console::warning($domain->get() . ' is not a publicly accessible domain. Skipping SSL certificate generation.');
            } elseif (str_starts_with($request->getURI(), '/.well-known/acme-challenge')) {
                Console::warning('Skipping SSL certificates generation on ACME challenge.');
            } else {
                $authorization->disable();

                $envDomain = System::getEnv('_APP_DOMAIN', '');
                $mainDomain = null;
                if (!empty($envDomain) && $envDomain !== 'localhost') {
                    $mainDomain = $envDomain;
                } else {
                    $domainDocument = $dbForConsole->findOne('rules', [Query::orderAsc('$id')]);
                    $mainDomain = $domainDocument ? $domainDocument->getAttribute('domain') : $domain->get();
                }

                if ($mainDomain !== $domain->get()) {
                    Console::warning($domain->get() . ' is not a main domain. Skipping SSL certificate generation.');
                } else {
                    $domainDocument = $dbForConsole->findOne('rules', [
                        Query::equal('domain', [$domain->get()])
                    ]);

                    if (!$domainDocument) {
                        $domainDocument = new Document([
                            'domain' => $domain->get(),
                            'resourceType' => 'api',
                            'status' => 'verifying',
                            'projectId' => 'console',
                            'projectInternalId' => 'console'
                        ]);

                        $domainDocument = $dbForConsole->createDocument('rules', $domainDocument);

                        Console::info('Issuing a TLS certificate for the main domain (' . $domain->get() . ') in a few seconds...');

                        $queueForCertificates
                            ->setDomain($domainDocument)
                            ->setSkipRenewCheck(true)
                            ->trigger();
                    }
                }
                $domains[$domain->get()] = true;

                $authorization->reset(); // ensure authorization is re-enabled
            }
            Config::setParam('domains', $domains);
        }

        $localeParam = (string) $request->getParam('locale', $request->getHeader('x-appwrite-locale', ''));
        if (\in_array($localeParam, $localeCodes)) {
            $locale->setDefault($localeParam);
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

        $isLocalHost = $request->getHostname() === 'localhost' || $request->getHostname() === 'localhost:' . $request->getPort();
        $isIpAddress = filter_var($request->getHostname(), FILTER_VALIDATE_IP) !== false;

        $isConsoleProject = $project->getAttribute('$id', '') === 'console';
        $isConsoleRootSession = System::getEnv('_APP_CONSOLE_ROOT_SESSION', 'disabled') === 'enabled';

        Config::setParam(
            'cookieDomain',
            $isLocalHost || $isIpAddress
                ? null
                : (
                    $isConsoleProject && $isConsoleRootSession
                    ? '.' . $selfDomain->getRegisterable()
                    : '.' . $request->getHostname()
                )
        );

        /*
        * Response format
        */
        $responseFormat = $request->getHeader('x-appwrite-response-format', System::getEnv('_APP_SYSTEM_RESPONSE_FORMAT', ''));
        if ($responseFormat) {
            if (version_compare($responseFormat, '1.4.0', '<')) {
                $response->addFilter(new ResponseV16());
            }
            if (version_compare($responseFormat, '1.5.0', '<')) {
                $response->addFilter(new ResponseV17());
            }
        }

        /*
        * Security Headers
        *
        * As recommended at:
        * @see https://www.owasp.org/index.php/List_of_useful_HTTP_headers
        */
        if (System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'enabled') { // Force HTTPS
            if ($request->getProtocol() !== 'https' // localhost allowed for proxy, APP_HOSTNAME_INTERNAL allowed for migrations
                && ($request->getHeader('host') ?? '') !== 'localhost'
                && ($request->getHeader('host') ?? '') !== APP_HOSTNAME_INTERNAL) {
                if ($request->getMethod() !== Request::METHOD_GET) {
                    throw new AppwriteException(AppwriteException::GENERAL_PROTOCOL_UNSUPPORTED, 'Method unsupported over HTTP. Please use HTTPS instead.');
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
            ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-Appwrite-JWT, X-Appwrite-Response-Format, X-Appwrite-Timeout, X-SDK-Version, X-SDK-Name, X-SDK-Language, X-SDK-Platform, X-SDK-GraphQL, X-Appwrite-ID, X-Appwrite-Timestamp, Content-Range, Range, Cache-Control, Expires, Pragma, X-Forwarded-For, X-Forwarded-User-Agent')
            ->addHeader('Access-Control-Expose-Headers', 'X-Appwrite-Session, X-Fallback-Cookies')
            ->addHeader('Access-Control-Allow-Origin', $refDomain)
            ->addHeader('Access-Control-Allow-Credentials', 'true');

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
    });

// Http::options()
//     ->inject('utopia')
//     ->inject('swooleRequest')
//     ->inject('request')
//     ->inject('response')
//     ->inject('dbForConsole')
//     ->inject('getProjectDB')
//     ->inject('queueForEvents')
//     ->inject('queueForUsage')
//     ->inject('geodb')
//     ->inject('auth')
//     ->action(function (Http $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Database $dbForConsole, callable $getProjectDB, Event $queueForEvents, Usage $queueForUsage, Reader $geodb, Authorization $auth) {
//         /*
//         * Appwrite Router
//         */
//         $host = $request->getHostname() ?? '';
//         $mainDomain = System::getEnv('_APP_DOMAIN', '');
//         // Only run Router when external domain
//         if ($host !== $mainDomain) {
//             if (router($utopia, $dbForConsole, $getProjectDB, $swooleRequest, $request, $response, $queueForEvents, $queueForUsage, $geodb, $auth)) {
//                 return;
//             }
//         }

//         $origin = $request->getOrigin();

//         $response
//             ->addHeader('Server', 'Appwrite')
//             ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
//             ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-Appwrite-JWT, X-Appwrite-Response-Format, X-Appwrite-Timeout, X-SDK-Version, X-SDK-Name, X-SDK-Language, X-SDK-Platform, X-SDK-GraphQL, X-Appwrite-ID, X-Appwrite-Timestamp, Content-Range, Range, Cache-Control, Expires, Pragma, X-Appwrite-Session, X-Fallback-Cookies, X-Forwarded-For, X-Forwarded-User-Agent')
//             ->addHeader('Access-Control-Expose-Headers', 'X-Appwrite-Session, X-Fallback-Cookies')
//             ->addHeader('Access-Control-Allow-Origin', $origin)
//             ->addHeader('Access-Control-Allow-Credentials', 'true')
//             ->noContent();
//     });

Http::error()
    ->inject('error')
    ->inject('user')
    ->inject('route')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('logger')
    ->inject('log')
    ->inject('authorization')
    ->inject('connections')
    ->action(function (Throwable $error, Document $user, ?Route $route, Request $request, Response $response, Document $project, ?Logger $logger, Log $log, Authorization $authorization, Connections $connections) {
        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

        if(is_null($route)) {
            $route = new Route($request->getMethod(), $request->getURI());
        }

        if ($error instanceof AppwriteException) {
            $publish = $error->isPublishable();
        } else {
            $publish = $error->getCode() === 0 || $error->getCode() >= 500;
        }

        if ($logger && ($publish || $error->getCode() === 0)) {
            if (isset($user) && !$user->isEmpty()) {
                $log->setUser(new User($user->getId()));
            }

            $log->setNamespace("http");
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());

            $log->addTag('database', $project->getAttribute('database', 'console'));
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
            $log->addExtra('roles', $authorization->getRoles());

            $action = $route->getLabel("sdk.namespace", "UNKNOWN_NAMESPACE") . '.' . $route->getLabel("sdk.method", "UNKNOWN_METHOD");
            $log->setAction($action);

            $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $responseCode = $logger->addLog($log);
            Console::info('Log pushed with status code: ' . $responseCode);
        }

        $code = $error->getCode();
        $message = $error->getMessage();
        $file = $error->getFile();
        $line = $error->getLine();
        $trace = $error->getTrace();

        if (php_sapi_name() === 'cli') {
            Console::error('[Error] ------------------');
            Console::error('[Error] Timestamp: ' . date('c', time()));

            if ($route) {
                Console::error('[Error] Method: ' . $route->getMethod());
                Console::error('[Error] URL: ' . $route->getPath());
            }

            Console::error('[Error] Code: ' . $code);
            Console::error('[Error] Type: ' . get_class($error));
            Console::error('[Error] Message: ' . $message);
            Console::error('[Error] File: ' . $file);
            Console::error('[Error] Line: ' . $line);
        }

        /** Handle Utopia Errors */
        if ($error instanceof Utopia\Http\Exception) {
            $error = new AppwriteException(AppwriteException::GENERAL_UNKNOWN, $message, $code, $error);
            switch ($code) {
                case 400:
                    $error->setType(AppwriteException::GENERAL_ARGUMENT_INVALID);
                    break;
                case 404:
                    $error->setType(AppwriteException::GENERAL_ROUTE_NOT_FOUND);
                    break;
            }
        } elseif ($error instanceof Utopia\Database\Exception\Conflict) {
            $error = new AppwriteException(AppwriteException::DOCUMENT_UPDATE_CONFLICT, previous: $error);
            $code = $error->getCode();
            $message = $error->getMessage();
        } elseif ($error instanceof Utopia\Database\Exception\Timeout) {
            $error = new AppwriteException(AppwriteException::DATABASE_TIMEOUT, previous: $error);
            $code = $error->getCode();
            $message = $error->getMessage();
        }

        /** Wrap all exceptions inside Appwrite\Extend\Exception */
        if (!($error instanceof AppwriteException)) {
            $error = new AppwriteException(AppwriteException::GENERAL_UNKNOWN, $message, (int)$code, $error);
        }

        switch ($code) { // Don't show 500 errors!
            case 400: // Error allowed publicly
            case 401: // Error allowed publicly
            case 402: // Error allowed publicly
            case 403: // Error allowed publicly
            case 404: // Error allowed publicly
            case 408: // Error allowed publicly
            case 409: // Error allowed publicly
            case 412: // Error allowed publicly
            case 416: // Error allowed publicly
            case 429: // Error allowed publicly
            case 451: // Error allowed publicly
            case 501: // Error allowed publicly
            case 503: // Error allowed publicly
                break;
            default:
                $code = 500; // All other errors get the generic 500 server error status code
                $message = (Http::getMode() === Http::MODE_TYPE_DEVELOPMENT) ? $message : 'Server Error';
        }

        //$_SERVER = []; // Reset before reporting to error log to avoid keys being compromised

        $type = $error->getType();

        $output = ((Http::isDevelopment())) ? [
            'message' => $message,
            'code' => $code,
            'file' => $file,
            'line' => $line,
            'trace' => \json_encode($trace, JSON_UNESCAPED_UNICODE) === false ? [] : $trace, // check for failing encode
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
            ->setStatusCode($code);

        $template = ($route) ? $route->getLabel('error', null) : null;

        if ($template) {
            $layout = new View($template);

            $layout
                ->setParam('title', $project->getAttribute('name') . ' - Error')
                ->setParam('development', Http::isDevelopment())
                ->setParam('projectName', $project->getAttribute('name'))
                ->setParam('projectURL', $project->getAttribute('url'))
                ->setParam('message', $output['message'] ?? '')
                ->setParam('type', $output['type'] ?? '')
                ->setParam('code', $output['code'] ?? '')
                ->setParam('trace', $output['trace'] ?? []);

            $response->html($layout->render());
        }

        $connections->reclaim();

        $response->dynamic(
            new Document($output),
            Http::isDevelopment() ? Response::MODEL_ERROR_DEV : Response::MODEL_ERROR
        );
    });

Http::get('/robots.txt')
    ->desc('Robots.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function (Response $response) {
        $template = new View(__DIR__ . '/../views/general/robots.phtml');
        $response->text($template->render(false));
    });

Http::get('/humans.txt')
    ->desc('Humans.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function (Response $response) {
        $template = new View(__DIR__ . '/../views/general/humans.phtml');
        $response->text($template->render(false));
    });

Http::get('/.well-known/acme-challenge/*')
    ->desc('SSL Verification')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('request')
    ->inject('response')
    ->action(function (Request $request, Response $response) {
        $uriChunks = \explode('/', $request->getURI());
        $token = $uriChunks[\count($uriChunks) - 1];

        $validator = new Text(100, allowList: [
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

//include_once __DIR__ . '/shared/api.php';
//include_once __DIR__ . '/shared/api/auth.php';

// Http::wildcard()
//     ->groups(['api'])
//     ->label('scope', 'global')
//     ->action(function () {
//         throw new AppwriteException(AppwriteException::GENERAL_ROUTE_NOT_FOUND);
//     });

foreach (Config::getParam('services', []) as $service) {
    //include_once $service['controller'];
}

include_once 'shared/api.php';
include_once 'shared/api/auth.php';
include_once 'api/account.php';
include_once 'api/avatars.php';
//include_once 'api/database.php';
//include_once 'api/functions.php';
//include_once 'api/graphql.php';
//include_once 'api/health.php';
include_once 'api/locale.php';
include_once 'api/messaging.php';
//include_once 'api/migrations.php';
include_once 'api/projects.php';
//include_once 'api/proxy.php';
include_once 'api/storage.php';
include_once 'api/teams.php';
include_once 'api/users.php';
//include_once 'api/vcs.php';
