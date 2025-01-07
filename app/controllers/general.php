<?php

require_once __DIR__ . '/../init.php';

use Ahc\Jwt\JWT;
use Appwrite\Auth\Auth;
use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Usage;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Request\Filters\V16 as RequestV16;
use Appwrite\Utopia\Request\Filters\V17 as RequestV17;
use Appwrite\Utopia\Request\Filters\V18 as RequestV18;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V16 as ResponseV16;
use Appwrite\Utopia\Response\Filters\V17 as ResponseV17;
use Appwrite\Utopia\Response\Filters\V18 as ResponseV18;
use Appwrite\Utopia\View;
use Executor\Executor;
use Swoole\Http\Request as SwooleRequest;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Domains\Domain;
use Utopia\DSN\DSN;
use Utopia\Locale\Locale;
use Utopia\Logger\Adapter\Sentry;
use Utopia\Logger\Log;
use Utopia\Logger\Log\User;
use Utopia\Logger\Logger;
use Utopia\System\System;
use Utopia\Validator\Hostname;
use Utopia\Validator\Text;

Config::setParam('domainVerification', false);
Config::setParam('cookieDomain', 'localhost');
Config::setParam('cookieSamesite', Response::COOKIE_SAMESITE_NONE);

function router(App $utopia, Database $dbForPlatform, callable $getProjectDB, SwooleRequest $swooleRequest, Request $request, Response $response, Event $queueForEvents, Usage $queueForUsage, Func $queueForFunctions, array $geoRecord, callable $isResourceBlocked, string $previewHostname)
{
    $utopia->getRoute()?->label('error', __DIR__ . '/../views/general/error.phtml');

    $host = $request->getHostname() ?? '';
    if (!empty($previewHostname)) {
        $host = $previewHostname;
    }

    // TODO: @christyjacob remove once we migrate the rules in 1.7.x
    if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
        $route = Authorization::skip(fn () => $dbForPlatform->getDocument('rules', md5($host)));
    } else {
        $route = Authorization::skip(
            fn () => $dbForPlatform->find('rules', [
                Query::equal('domain', [$host]),
                Query::limit(1)
            ])
        )[0] ?? new Document();
    }

    if ($route->isEmpty()) {
        if ($host === System::getEnv('_APP_DOMAIN_FUNCTIONS', '')) {
            throw new AppwriteException(AppwriteException::GENERAL_ACCESS_FORBIDDEN, 'This domain cannot be used for security reasons. Please use any subdomain instead.');
        }

        if (\str_ends_with($host, System::getEnv('_APP_DOMAIN_FUNCTIONS', ''))) {
            throw new AppwriteException(AppwriteException::GENERAL_ACCESS_FORBIDDEN, 'This domain is not connected to any Appwrite resource yet. Please configure custom domain or function domain to allow this request.');
        }

        if (System::getEnv('_APP_OPTIONS_ROUTER_PROTECTION', 'disabled') === 'enabled') {
            if ($host !== 'localhost' && $host !== APP_HOSTNAME_INTERNAL && $host !== System::getEnv('_APP_CONSOLE_DOMAIN', '')) {
                throw new AppwriteException(AppwriteException::GENERAL_ACCESS_FORBIDDEN, 'Router protection does not allow accessing Appwrite over this domain. Please add it as custom domain to your project or disable _APP_OPTIONS_ROUTER_PROTECTION environment variable.');
            }
        }

        // Act as API - no Proxy logic
        $utopia->getRoute()?->label('error', '');
        return false;
    }

    $projectId = $route->getAttribute('projectId');
    $project = Authorization::skip(
        fn () => $dbForPlatform->getDocument('projects', $projectId)
    );

    if (!$project->isEmpty() && $project->getId() !== 'console') {
        $accessedAt = $project->getAttribute('accessedAt', '');
        if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $accessedAt) {
            $project->setAttribute('accessedAt', DateTime::now());
            Authorization::skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $project));
        }
    }

    if (array_key_exists('proxy', $project->getAttribute('services', []))) {
        $status = $project->getAttribute('services', [])['proxy'];
        if (!$status) {
            throw new AppwriteException(AppwriteException::GENERAL_SERVICE_DISABLED);
        }
    }

    // Skip Appwrite Router for ACME challenge. Nessessary for certificate generation
    $path = ($swooleRequest->server['request_uri'] ?? '/');
    if (\str_starts_with($path, '/.well-known/acme-challenge')) {
        return false;
    }

    $type = $route->getAttribute('resourceType');

    if ($type === 'function') {
        $utopia->getRoute()?->label('sdk.namespace', 'functions');
        $utopia->getRoute()?->label('sdk.method', 'createExecution');

        if (System::getEnv('_APP_OPTIONS_FUNCTIONS_FORCE_HTTPS', 'disabled') === 'enabled') { // Force HTTPS
            if ($request->getProtocol() !== 'https') {
                if ($request->getMethod() !== Request::METHOD_GET) {
                    throw new AppwriteException(AppwriteException::GENERAL_PROTOCOL_UNSUPPORTED, 'Method unsupported over HTTP. Please use HTTPS instead.');
                }

                return $response->redirect('https://' . $request->getHostname() . $request->getURI());
            }
        }

        $functionId = $route->getAttribute('resourceId');
        $projectId = $route->getAttribute('projectId');

        $path = ($swooleRequest->server['request_uri'] ?? '/');
        $query = ($swooleRequest->server['query_string'] ?? '');
        if (!empty($query)) {
            $path .= '?' . $query;
        }


        $body = $swooleRequest->getContent() ?? '';
        $method = $swooleRequest->server['request_method'];

        $requestHeaders = $request->getHeaders();

        $project = Authorization::skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

        $dbForProject = $getProjectDB($project);

        $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));

        if ($function->isEmpty() || !$function->getAttribute('enabled')) {
            throw new AppwriteException(AppwriteException::FUNCTION_NOT_FOUND);
        }

        if ($isResourceBlocked($project, RESOURCE_TYPE_FUNCTIONS, $functionId)) {
            throw new AppwriteException(AppwriteException::GENERAL_RESOURCE_BLOCKED);
        }

        $version = $function->getAttribute('version', 'v2');
        $runtimes = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', []);
        $spec = Config::getParam('runtime-specifications')[$function->getAttribute('specification', APP_FUNCTION_SPECIFICATION_DEFAULT)];

        $runtime = (isset($runtimes[$function->getAttribute('runtime', '')])) ? $runtimes[$function->getAttribute('runtime', '')] : null;

        if (\is_null($runtime)) {
            throw new AppwriteException(AppwriteException::FUNCTION_RUNTIME_UNSUPPORTED, 'Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        $deployment = Authorization::skip(fn () => $dbForProject->getDocument('deployments', $function->getAttribute('deployment', '')));

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new AppwriteException(AppwriteException::DEPLOYMENT_NOT_FOUND, 'Deployment not found. Create a deployment before trying to execute a function');
        }

        if ($deployment->isEmpty()) {
            throw new AppwriteException(AppwriteException::DEPLOYMENT_NOT_FOUND, 'Deployment not found. Create a deployment before trying to execute a function');
        }

        /** Check if build has completed */
        $build = Authorization::skip(fn () => $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', '')));
        if ($build->isEmpty()) {
            throw new AppwriteException(AppwriteException::BUILD_NOT_FOUND);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new AppwriteException(AppwriteException::BUILD_NOT_READY);
        }

        $permissions = $function->getAttribute('execute');

        if (!(\in_array('any', $permissions)) && !(\in_array('guests', $permissions))) {
            throw new AppwriteException(AppwriteException::USER_UNAUTHORIZED, 'To execute function using domain, execute permissions must include "any" or "guests"');
        }

        $jwtExpiry = $function->getAttribute('timeout', 900);
        $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $jwtExpiry, 0);
        $apiKey = $jwtObj->encode([
            'projectId' => $project->getId(),
            'scopes' => $function->getAttribute('scopes', [])
        ]);

        $headers = \array_merge([], $requestHeaders);
        $headers['x-appwrite-key'] = API_KEY_DYNAMIC . '_' . $apiKey;
        $headers['x-appwrite-trigger'] = 'http';
        $headers['x-appwrite-user-id'] = '';
        $headers['x-appwrite-user-jwt'] = '';
        $headers['x-appwrite-country-code'] = $geoRecord['countryCode'];
        $headers['x-appwrite-continent-code'] = $geoRecord['contenentCode'];

        $eu = Config::getParam('locale-eu');
        $headers['x-appwrite-continent-eu'] = (\in_array($geoRecord['countryCode'], $eu)) ? 'true' : 'false';

        $headersFiltered = [];
        foreach ($headers as $key => $value) {
            if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_REQUEST)) {
                $headersFiltered[] = ['name' => $key, 'value' => $value];
            }
        }

        $executionId = ID::unique();

        $execution = new Document([
            '$id' => $executionId,
            '$permissions' => [],
            'functionInternalId' => $function->getInternalId(),
            'functionId' => $function->getId(),
            'deploymentInternalId' => $deployment->getInternalId(),
            'deploymentId' => $deployment->getId(),
            'trigger' => 'http', // http / schedule / event
            'status' =>  'processing', // waiting / processing / completed / failed
            'responseStatusCode' => 0,
            'responseHeaders' => [],
            'requestPath' => $path,
            'requestMethod' => $method,
            'requestHeaders' => $headersFiltered,
            'errors' => '',
            'logs' => '',
            'duration' => 0.0,
            'search' => implode(' ', [$functionId, $executionId]),
        ]);

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setContext('function', $function);

        $durationStart = \microtime(true);

        $vars = [];

        // V2 vars
        if ($version === 'v2') {
            $vars = \array_merge($vars, [
                'APPWRITE_FUNCTION_TRIGGER' => $headers['x-appwrite-trigger'] ?? '',
                'APPWRITE_FUNCTION_DATA' => $body ?? '',
                'APPWRITE_FUNCTION_USER_ID' => $headers['x-appwrite-user-id'] ?? '',
                'APPWRITE_FUNCTION_JWT' => $headers['x-appwrite-user-jwt'] ?? ''
            ]);
        }

        // Shared vars
        foreach ($function->getAttribute('varsProject', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }

        // Function vars
        foreach ($function->getAttribute('vars', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
        $hostname = System::getEnv('_APP_DOMAIN');
        $endpoint = $protocol . '://' . $hostname . "/v1";

        // Appwrite vars
        $vars = \array_merge($vars, [
            'APPWRITE_FUNCTION_API_ENDPOINT' => $endpoint,
            'APPWRITE_FUNCTION_ID' => $functionId,
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name'),
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
            'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
            'APPWRITE_FUNCTION_CPUS' => $spec['cpus'] ?? APP_FUNCTION_CPUS_DEFAULT,
            'APPWRITE_FUNCTION_MEMORY' => $spec['memory'] ?? APP_FUNCTION_MEMORY_DEFAULT,
            'APPWRITE_VERSION' => APP_VERSION_STABLE,
            'APPWRITE_REGION' => $project->getAttribute('region'),
            'APPWRITE_DEPLOYMENT_TYPE' => $deployment->getAttribute('type', ''),
            'APPWRITE_VCS_REPOSITORY_ID' => $deployment->getAttribute('providerRepositoryId', ''),
            'APPWRITE_VCS_REPOSITORY_NAME' => $deployment->getAttribute('providerRepositoryName', ''),
            'APPWRITE_VCS_REPOSITORY_OWNER' => $deployment->getAttribute('providerRepositoryOwner', ''),
            'APPWRITE_VCS_REPOSITORY_URL' => $deployment->getAttribute('providerRepositoryUrl', ''),
            'APPWRITE_VCS_REPOSITORY_BRANCH' => $deployment->getAttribute('providerBranch', ''),
            'APPWRITE_VCS_REPOSITORY_BRANCH_URL' => $deployment->getAttribute('providerBranchUrl', ''),
            'APPWRITE_VCS_COMMIT_HASH' => $deployment->getAttribute('providerCommitHash', ''),
            'APPWRITE_VCS_COMMIT_MESSAGE' => $deployment->getAttribute('providerCommitMessage', ''),
            'APPWRITE_VCS_COMMIT_URL' => $deployment->getAttribute('providerCommitUrl', ''),
            'APPWRITE_VCS_COMMIT_AUTHOR_NAME' => $deployment->getAttribute('providerCommitAuthor', ''),
            'APPWRITE_VCS_COMMIT_AUTHOR_URL' => $deployment->getAttribute('providerCommitAuthorUrl', ''),
            'APPWRITE_VCS_ROOT_DIRECTORY' => $deployment->getAttribute('providerRootDirectory', ''),
        ]);

        /** Execute function */
        $executor = new Executor(System::getEnv('_APP_EXECUTOR_HOST'));
        try {
            $version = $function->getAttribute('version', 'v2');
            $command = $runtime['startCommand'];
            $command = $version === 'v2' ? '' : 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"';
            $executionResponse = $executor->createExecution(
                projectId: $project->getId(),
                deploymentId: $deployment->getId(),
                body: \strlen($body) > 0 ? $body : null,
                variables: $vars,
                timeout: $function->getAttribute('timeout', 0),
                image: $runtime['image'],
                source: $build->getAttribute('path', ''),
                entrypoint: $deployment->getAttribute('entrypoint', ''),
                version: $version,
                path: $path,
                method: $method,
                headers: $headers,
                runtimeEntrypoint: $command,
                cpus: $spec['cpus'] ?? APP_FUNCTION_CPUS_DEFAULT,
                memory: $spec['memory'] ?? APP_FUNCTION_MEMORY_DEFAULT,
                logging: $function->getAttribute('logging', true),
                requestTimeout: 30
            );

            $headersFiltered = [];
            foreach ($executionResponse['headers'] as $key => $value) {
                if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_RESPONSE)) {
                    $headersFiltered[] = ['name' => $key, 'value' => $value];
                }
            }

            /** Update execution status */
            $status = $executionResponse['statusCode'] >= 500 ? 'failed' : 'completed';
            $execution->setAttribute('status', $status);
            $execution->setAttribute('responseStatusCode', $executionResponse['statusCode']);
            $execution->setAttribute('responseHeaders', $headersFiltered);
            $execution->setAttribute('logs', $executionResponse['logs']);
            $execution->setAttribute('errors', $executionResponse['errors']);
            $execution->setAttribute('duration', $executionResponse['duration']);

        } catch (\Throwable $th) {
            $durationEnd = \microtime(true);

            $execution
                ->setAttribute('duration', $durationEnd - $durationStart)
                ->setAttribute('status', 'failed')
                ->setAttribute('responseStatusCode', 500)
                ->setAttribute('errors', $th->getMessage() . '\nError Code: ' . $th->getCode());
            Console::error($th->getMessage());

            if ($th instanceof AppwriteException) {
                throw $th;
            }
        } finally {
            $queueForFunctions
                ->setType(Func::TYPE_ASYNC_WRITE)
                ->setExecution($execution)
                ->setProject($project)
                ->trigger();
        }

        $execution->setAttribute('logs', '');
        $execution->setAttribute('errors', '');

        $headers = [];
        foreach (($executionResponse['headers'] ?? []) as $key => $value) {
            $headers[] = ['name' => $key, 'value' => $value];
        }

        $execution->setAttribute('responseBody', $executionResponse['body'] ?? '');
        $execution->setAttribute('responseHeaders', $headers);

        $body = $execution['responseBody'] ?? '';

        $contentType = 'text/plain';
        foreach ($execution['responseHeaders'] as $header) {
            if (\strtolower($header['name']) === 'content-type') {
                $contentType = $header['value'];
            }

            $response->setHeader($header['name'], $header['value']);
        }

        $response
            ->setContentType($contentType)
            ->setStatusCode($execution['responseStatusCode'] ?? 200)
            ->send($body);

        $fileSize = 0;
        $file = $request->getFiles('file');
        if (!empty($file)) {
            $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];
        }

        $queueForUsage
            ->addMetric(METRIC_NETWORK_REQUESTS, 1)
            ->addMetric(METRIC_NETWORK_INBOUND, $request->getSize() + $fileSize)
            ->addMetric(METRIC_NETWORK_OUTBOUND, $response->getSize())
            ->addMetric(METRIC_EXECUTIONS, 1)
            ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS), 1)
            ->addMetric(METRIC_EXECUTIONS_COMPUTE, (int)($execution->getAttribute('duration') * 1000)) // per project
            ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS_COMPUTE), (int)($execution->getAttribute('duration') * 1000)) // per function
            ->addMetric(METRIC_EXECUTIONS_MB_SECONDS, (int)(($spec['memory'] ?? APP_FUNCTION_MEMORY_DEFAULT) * $execution->getAttribute('duration', 0) * ($spec['cpus'] ?? APP_FUNCTION_CPUS_DEFAULT)))
            ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS_MB_SECONDS), (int)(($spec['memory'] ?? APP_FUNCTION_MEMORY_DEFAULT) * $execution->getAttribute('duration', 0) * ($spec['cpus'] ?? APP_FUNCTION_CPUS_DEFAULT)))
            ->setProject($project)
            ->trigger()
        ;

        return true;
    } elseif ($type === 'api') {
        $utopia->getRoute()?->label('error', '');
        return false;
    } else {
        throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Unknown resource type ' . $type);
    }

    $utopia->getRoute()?->label('error', '');
    return false;
}

/*
App::init()
    ->groups(['api'])
    ->inject('project')
    ->inject('mode')
    ->action(function (Document $project, string $mode) {
        if ($mode === APP_MODE_ADMIN && $project->getId() === 'console') {
            throw new AppwriteException(AppwriteException::GENERAL_BAD_REQUEST, 'Admin mode is not allowed for console project');
        }
    });
*/

App::init()
    ->groups(['database', 'functions', 'storage', 'messaging'])
    ->inject('project')
    ->inject('request')
    ->action(function (Document $project, Request $request) {
        if ($project->getId() === 'console') {
            $message = empty($request->getHeader('x-appwrite-project')) ?
                'No Appwrite project was specified. Please specify your project ID when initializing your Appwrite SDK.' :
                'This endpoint is not available for the console project. The Appwrite Console is a reserved project ID and cannot be used with the Appwrite SDKs and APIs. Please check if your project ID is correct.';
            throw new AppwriteException(AppwriteException::GENERAL_ACCESS_FORBIDDEN, $message);
        }
    });

App::init()
    ->groups(['api', 'web'])
    ->inject('utopia')
    ->inject('swooleRequest')
    ->inject('request')
    ->inject('response')
    ->inject('console')
    ->inject('project')
    ->inject('dbForPlatform')
    ->inject('getProjectDB')
    ->inject('locale')
    ->inject('localeCodes')
    ->inject('clients')
    ->inject('geoRecord')
    ->inject('queueForUsage')
    ->inject('queueForEvents')
    ->inject('queueForCertificates')
    ->inject('queueForFunctions')
    ->inject('isResourceBlocked')
    ->inject('previewHostname')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Document $console, Document $project, Database $dbForPlatform, callable $getProjectDB, Locale $locale, array $localeCodes, array $clients, array $geoRecord, Usage $queueForUsage, Event $queueForEvents, Certificate $queueForCertificates, Func $queueForFunctions, callable $isResourceBlocked, string $previewHostname) {
        /*
        * Appwrite Router
        */
        $host = $request->getHostname() ?? '';
        $mainDomain = System::getEnv('_APP_DOMAIN', '');
        // Only run Router when external domain
        if ($host !== $mainDomain || !empty($previewHostname)) {
            if (router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $queueForEvents, $queueForUsage, $queueForFunctions, $geoRecord, $isResourceBlocked, $previewHostname)) {
                return;
            }
        }

        /*
        * Request format
        */
        $route = $utopia->getRoute();
        Request::setRoute($route);

        if ($route === null) {
            return $response
                ->setStatusCode(404)
                ->send('Not Found');
        }

        $requestFormat = $request->getHeader('x-appwrite-response-format', System::getEnv('_APP_SYSTEM_RESPONSE_FORMAT', ''));
        if ($requestFormat) {
            if (version_compare($requestFormat, '1.4.0', '<')) {
                $request->addFilter(new RequestV16());
            }
            if (version_compare($requestFormat, '1.5.0', '<')) {
                $request->addFilter(new RequestV17());
            }
            if (version_compare($requestFormat, '1.6.0', '<')) {
                $request->addFilter(new RequestV18());
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
                Authorization::disable();

                $envDomain = System::getEnv('_APP_DOMAIN', '');
                $mainDomain = null;
                if (!empty($envDomain) && $envDomain !== 'localhost') {
                    $mainDomain = $envDomain;
                } else {
                    // TODO: @christyjacob remove once we migrate the rules in 1.7.x
                    if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
                        $domainDocument = $dbForPlatform->getDocument('rules', md5($envDomain));
                    } else {
                        $domainDocument = $dbForPlatform->findOne('rules', [Query::orderAsc('$id')]);
                    }
                    $mainDomain = !$domainDocument->isEmpty() ? $domainDocument->getAttribute('domain') : $domain->get();
                }

                if ($mainDomain !== $domain->get()) {
                    Console::warning($domain->get() . ' is not a main domain. Skipping SSL certificate generation.');
                } else {
                    // TODO: @christyjacob remove once we migrate the rules in 1.7.x
                    if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
                        $domainDocument = $dbForPlatform->getDocument('rules', md5($domain->get()));
                    } else {
                        $domainDocument = $dbForPlatform->findOne('rules', [
                            Query::equal('domain', [$domain->get()])
                        ]);
                    }

                    if ($domainDocument->isEmpty()) {
                        $domainDocument = new Document([
                            // TODO: @christyjacob remove once we migrate the rules in 1.7.x
                            '$id' => System::getEnv('_APP_RULES_FORMAT') === 'md5' ? md5($domain->get()) : ID::unique(),
                            'domain' => $domain->get(),
                            'resourceType' => 'api',
                            'status' => 'verifying',
                            'projectId' => 'console',
                            'projectInternalId' => 'console'
                        ]);

                        $domainDocument = $dbForPlatform->createDocument('rules', $domainDocument);

                        Console::info('Issuing a TLS certificate for the main domain (' . $domain->get() . ') in a few seconds...');

                        $queueForCertificates
                            ->setDomain($domainDocument)
                            ->setSkipRenewCheck(true)
                            ->trigger();
                    }
                }
                $domains[$domain->get()] = true;

                Authorization::reset(); // ensure authorization is re-enabled
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
            if (version_compare($responseFormat, '1.6.0', '<')) {
                $response->addFilter(new ResponseV18());
            }
            if (version_compare($responseFormat, APP_VERSION_STABLE, '>')) {
                $response->addHeader('X-Appwrite-Warning', "The current SDK is built for Appwrite " . $responseFormat . ". However, the current Appwrite server version is ". APP_VERSION_STABLE . ". Please downgrade your SDK to match the Appwrite version: https://appwrite.io/docs/sdks");
            }
        }

        /*
        * Security Headers
        *
        * As recommended at:
        * @see https://www.owasp.org/index.php/List_of_useful_HTTP_headers
        */
        if (System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'enabled') { // Force HTTPS
            if ($request->getProtocol() !== 'https' && ($swooleRequest->header['host'] ?? '') !== 'localhost' && ($swooleRequest->header['host'] ?? '') !== APP_HOSTNAME_INTERNAL) { // localhost allowed for proxy, APP_HOSTNAME_INTERNAL allowed for migrations
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

App::options()
    ->inject('utopia')
    ->inject('swooleRequest')
    ->inject('request')
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('getProjectDB')
    ->inject('queueForEvents')
    ->inject('queueForUsage')
    ->inject('queueForFunctions')
    ->inject('geoRecord')
    ->inject('isResourceBlocked')
    ->inject('previewHostname')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Database $dbForPlatform, callable $getProjectDB, Event $queueForEvents, Usage $queueForUsage, Func $queueForFunctions, array $geoRecord, callable $isResourceBlocked, string $previewHostname) {
        /*
        * Appwrite Router
        */
        $host = $request->getHostname() ?? '';
        $mainDomain = System::getEnv('_APP_DOMAIN', '');
        // Only run Router when external domain
        if ($host !== $mainDomain || !empty($previewHostname)) {
            if (router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $queueForEvents, $queueForUsage, $queueForFunctions, $geoRecord, $isResourceBlocked, $previewHostname)) {
                return;
            }
        }

        $origin = $request->getOrigin();

        $response
            ->addHeader('Server', 'Appwrite')
            ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
            ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-Appwrite-JWT, X-Appwrite-Response-Format, X-Appwrite-Timeout, X-SDK-Version, X-SDK-Name, X-SDK-Language, X-SDK-Platform, X-SDK-GraphQL, X-Appwrite-ID, X-Appwrite-Timestamp, Content-Range, Range, Cache-Control, Expires, Pragma, X-Appwrite-Session, X-Fallback-Cookies, X-Forwarded-For, X-Forwarded-User-Agent')
            ->addHeader('Access-Control-Expose-Headers', 'X-Appwrite-Session, X-Fallback-Cookies')
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
    ->inject('log')
    ->inject('queueForUsage')
    ->action(function (Throwable $error, App $utopia, Request $request, Response $response, Document $project, ?Logger $logger, Log $log, Usage $queueForUsage) {
        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');
        $route = $utopia->getRoute();
        $class = \get_class($error);
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

        switch ($class) {
            case 'Utopia\Exception':
                $error = new AppwriteException(AppwriteException::GENERAL_UNKNOWN, $message, $code, $error);
                switch ($code) {
                    case 400:
                        $error->setType(AppwriteException::GENERAL_ARGUMENT_INVALID);
                        break;
                    case 404:
                        $error->setType(AppwriteException::GENERAL_ROUTE_NOT_FOUND);
                        break;
                }
                break;
            case 'Utopia\Database\Exception\Conflict':
                $error = new AppwriteException(AppwriteException::DOCUMENT_UPDATE_CONFLICT, previous: $error);
                break;
            case 'Utopia\Database\Exception\Timeout':
                $error = new AppwriteException(AppwriteException::DATABASE_TIMEOUT, previous: $error);
                break;
            case 'Utopia\Database\Exception\Query':
                $error = new AppwriteException(AppwriteException::GENERAL_QUERY_INVALID, $error->getMessage(), previous: $error);
                break;
            case 'Utopia\Database\Exception\Structure':
                $error = new AppwriteException(AppwriteException::DOCUMENT_INVALID_STRUCTURE, $error->getMessage(), previous: $error);
                break;
            case 'Utopia\Database\Exception\Duplicate':
                $error = new AppwriteException(AppwriteException::DOCUMENT_ALREADY_EXISTS);
                break;
            case 'Utopia\Database\Exception\Restricted':
                $error = new AppwriteException(AppwriteException::DOCUMENT_DELETE_RESTRICTED);
                break;
            case 'Utopia\Database\Exception\Authorization':
                $error = new AppwriteException(AppwriteException::USER_UNAUTHORIZED);
                break;
            case 'Utopia\Database\Exception\Relationship':
                $error = new AppwriteException(AppwriteException::RELATIONSHIP_VALUE_INVALID, $error->getMessage(), previous: $error);
                break;
            case 'Utopia\Database\Exception\NotFound':
                $error = new AppwriteException(AppwriteException::COLLECTION_NOT_FOUND, $error->getMessage(), previous: $error);
        }

        $code = $error->getCode();
        $message = $error->getMessage();

        if ($error instanceof AppwriteException) {
            $publish = $error->isPublishable();
        } else {
            $publish = $error->getCode() === 0 || $error->getCode() >= 500;
        }

        if ($error->getCode() >= 400 && $error->getCode() < 500) {
            // Register error logger
            $providerName = System::getEnv('_APP_EXPERIMENT_LOGGING_PROVIDER', '');
            $providerConfig = System::getEnv('_APP_EXPERIMENT_LOGGING_CONFIG', '');

            try {
                $loggingProvider = new DSN($providerConfig ?? '');
                $providerName = $loggingProvider->getScheme();

                if (!empty($providerName) && $providerName === 'sentry') {
                    $key = $loggingProvider->getPassword();
                    $projectId = $loggingProvider->getUser() ?? '';
                    $host = 'https://' . $loggingProvider->getHost();

                    $adapter = new Sentry($projectId, $key, $host);
                    $logger = new Logger($adapter);
                    $logger->setSample(0.01);
                    $publish = true;
                } else {
                    throw new \Exception('Invalid experimental logging provider');
                }
            } catch (\Throwable $th) {
                Console::warning('Failed to initialize logging provider: ' . $th->getMessage());
            }
        }

        if ($publish && $project->getId() !== 'console') {
            if (!Auth::isPrivilegedUser(Authorization::getRoles())) {
                $fileSize = 0;
                $file = $request->getFiles('file');
                if (!empty($file)) {
                    $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];
                }

                $queueForUsage
                    ->addMetric(METRIC_NETWORK_REQUESTS, 1)
                    ->addMetric(METRIC_NETWORK_INBOUND, $request->getSize() + $fileSize)
                    ->addMetric(METRIC_NETWORK_OUTBOUND, $response->getSize());
            }

            $queueForUsage
                ->setProject($project)
                ->trigger();
        }


        if ($logger && $publish) {
            try {
                /** @var Utopia\Database\Document $user */
                $user = $utopia->getResource('user');
            } catch (\Throwable) {
                // All good, user is optional information for logger
            }

            if (isset($user) && !$user->isEmpty()) {
                $log->setUser(new User($user->getId()));
            } else {
                $log->setUser(new User('guest-' . hash('sha256', $request->getIP())));
            }

            try {
                $dsn = new DSN($project->getAttribute('database', 'console'));
            } catch (\InvalidArgumentException) {
                // TODO: Temporary until all projects are using shared tables
                $dsn = new DSN('mysql://' . $project->getAttribute('database', 'console'));
            }

            $log->setNamespace("http");
            $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());

            $log->addTag('database', $dsn->getHost());
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
            $log->addExtra('roles', Authorization::getRoles());

            $action = $route->getLabel("sdk.namespace", "UNKNOWN_NAMESPACE") . '.' . $route->getLabel("sdk.method", "UNKNOWN_METHOD");
            $log->setAction($action);
            $log->addTag('service', $action);

            $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            try {
                $responseCode = $logger->addLog($log);
                Console::info('Error log pushed with status code: ' . $responseCode);
            } catch (Throwable $th) {
                Console::error('Error pushing log: ' . $th->getMessage());
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
                $message = 'Server Error';
        }

        //$_SERVER = []; // Reset before reporting to error log to avoid keys being compromised

        $type = $error->getType();

        $output = ((App::isDevelopment())) ? [
            'message' => $message,
            'code' => $code,
            'file' => $file,
            'line' => $line,
            'trace' => \json_encode($trace, JSON_UNESCAPED_UNICODE) === false ? [] : $trace, // check for failing encode
            'version' => APP_VERSION_STABLE,
            'type' => $type,
        ] : [
            'message' => $message,
            'code' => $code,
            'version' => APP_VERSION_STABLE,
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
                ->setParam('development', App::isDevelopment())
                ->setParam('projectName', $project->getAttribute('name'))
                ->setParam('projectURL', $project->getAttribute('url'))
                ->setParam('message', $output['message'] ?? '')
                ->setParam('type', $output['type'] ?? '')
                ->setParam('code', $output['code'] ?? '')
                ->setParam('trace', $output['trace'] ?? []);

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
    ->inject('utopia')
    ->inject('swooleRequest')
    ->inject('request')
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('getProjectDB')
    ->inject('queueForEvents')
    ->inject('queueForUsage')
    ->inject('queueForFunctions')
    ->inject('geoRecord')
    ->inject('isResourceBlocked')
    ->inject('previewHostname')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Database $dbForPlatform, callable $getProjectDB, Event $queueForEvents, Usage $queueForUsage, Func $queueForFunctions, array $geoRecord, callable $isResourceBlocked, string $previewHostname) {
        $host = $request->getHostname() ?? '';
        $mainDomain = System::getEnv('_APP_DOMAIN', '');

        if (($host === $mainDomain || $host === 'localhost') && empty($previewHostname)) {
            $template = new View(__DIR__ . '/../views/general/robots.phtml');
            $response->text($template->render(false));
        } else {
            router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $queueForEvents, $queueForUsage, $queueForFunctions, $geoRecord, $isResourceBlocked, $previewHostname);
        }
    });

App::get('/humans.txt')
    ->desc('Humans.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('utopia')
    ->inject('swooleRequest')
    ->inject('request')
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('getProjectDB')
    ->inject('queueForEvents')
    ->inject('queueForUsage')
    ->inject('queueForFunctions')
    ->inject('geoRecord')
    ->inject('isResourceBlocked')
    ->inject('previewHostname')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Database $dbForPlatform, callable $getProjectDB, Event $queueForEvents, Usage $queueForUsage, Func $queueForFunctions, array $geoRecord, callable $isResourceBlocked, string $previewHostname) {
        $host = $request->getHostname() ?? '';
        $mainDomain = System::getEnv('_APP_DOMAIN', '');

        if (($host === $mainDomain || $host === 'localhost') && empty($previewHostname)) {
            $template = new View(__DIR__ . '/../views/general/humans.phtml');
            $response->text($template->render(false));
        } else {
            router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $queueForEvents, $queueForUsage, $queueForFunctions, $geoRecord, $isResourceBlocked, $previewHostname);
        }
    });

App::get('/.well-known/acme-challenge/*')
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

include_once __DIR__ . '/shared/api.php';
include_once __DIR__ . '/shared/api/auth.php';

App::get('/v1/ping')
    ->groups(['api', 'general'])
    ->desc('Test the connection between the Appwrite and the SDK.')
    ->label('scope', 'global')
    ->label('event', 'projects.[projectId].ping')
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->inject('queueForEvents')
    ->action(function (Response $response, Document $project, Database $dbForPlatform, Event $queueForEvents) {
        if ($project->isEmpty()) {
            throw new AppwriteException(AppwriteException::PROJECT_NOT_FOUND);
        }

        $pingCount = $project->getAttribute('pingCount', 0) + 1;
        $pingedAt = DateTime::now();

        $project
            ->setAttribute('pingCount', $pingCount)
            ->setAttribute('pingedAt', $pingedAt);

        Authorization::skip(function () use ($dbForPlatform, $project) {
            $dbForPlatform->updateDocument('projects', $project->getId(), $project);
        });

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setPayload($response->output($project, Response::MODEL_PROJECT));

        $response->text('Pong!');
    });

App::wildcard()
    ->groups(['api'])
    ->label('scope', 'global')
    ->action(function () {
        throw new AppwriteException(AppwriteException::GENERAL_ROUTE_NOT_FOUND);
    });

foreach (Config::getParam('services', []) as $service) {
    include_once $service['controller'];
}
