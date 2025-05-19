<?php

require_once __DIR__ . '/../init.php';

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Auth\Auth;
use Appwrite\Auth\Key;
use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Network\Validator\Origin;
use Appwrite\Platform\Appwrite;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Transformation\Adapter\Preview;
use Appwrite\Transformation\Transformation;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Request\Filters\V16 as RequestV16;
use Appwrite\Utopia\Request\Filters\V17 as RequestV17;
use Appwrite\Utopia\Request\Filters\V18 as RequestV18;
use Appwrite\Utopia\Request\Filters\V19 as RequestV19;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V16 as ResponseV16;
use Appwrite\Utopia\Response\Filters\V17 as ResponseV17;
use Appwrite\Utopia\Response\Filters\V18 as ResponseV18;
use Appwrite\Utopia\Response\Filters\V19 as ResponseV19;
use Appwrite\Utopia\View;
use Executor\Executor;
use MaxMind\Db\Reader;
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
use Utopia\Platform\Service;
use Utopia\System\System;
use Utopia\Validator\Hostname;
use Utopia\Validator\Text;

Config::setParam('domainVerification', false);
Config::setParam('cookieDomain', 'localhost');
Config::setParam('cookieSamesite', Response::COOKIE_SAMESITE_NONE);

function router(App $utopia, Database $dbForPlatform, callable $getProjectDB, SwooleRequest $swooleRequest, Request $request, Response $response, Event $queueForEvents, StatsUsage $queueForStatsUsage, Func $queueForFunctions, Executor $executor, Reader $geodb, callable $isResourceBlocked, string $previewHostname, ?Key $apiKey)
{
    $host = $request->getHostname() ?? '';
    if (!empty($previewHostname)) {
        $host = $previewHostname;
    }

    // TODO: @christyjacob remove once we migrate the rules in 1.7.x
    if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
        $rule = Authorization::skip(fn () => $dbForPlatform->getDocument('rules', md5($host)));
    } else {
        $rule = Authorization::skip(
            fn () => $dbForPlatform->find('rules', [
                Query::equal('domain', [$host]),
                Query::limit(1)
            ])
        )[0] ?? new Document();
    }

    $errorView = __DIR__ . '/../views/general/error.phtml';
    $url = (System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https') . '://' . System::getEnv('_APP_DOMAIN', '');

    if ($rule->isEmpty()) {
        $appDomainFunctionsFallback = System::getEnv('_APP_DOMAIN_FUNCTIONS_FALLBACK', '');
        $appDomainFunctions = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');
        $appDomainSites = System::getEnv('_APP_DOMAIN_SITES', '');
        if (!empty($appDomainFunctionsFallback) && \str_ends_with($host, $appDomainFunctionsFallback)) {
            $appDomainFunctions = $appDomainFunctionsFallback;
        }

        if ($host === $appDomainFunctions || $host === $appDomainSites) {
            throw new AppwriteException(AppwriteException::GENERAL_ACCESS_FORBIDDEN, 'This domain cannot be used for security reasons. Please use any subdomain instead.', view: $errorView);
        }

        if (\str_ends_with($host, $appDomainFunctions) || \str_ends_with($host, $appDomainSites)) {
            $exception = new AppwriteException(AppwriteException::RULE_NOT_FOUND, 'This domain is not connected to any Appwrite resources. Visit domains tab under function/site settings to configure it.', view: $errorView);

            $exception->addCTA('Start with this domain', $url . '/console');
            throw $exception;
        }

        if (System::getEnv('_APP_OPTIONS_ROUTER_PROTECTION', 'disabled') === 'enabled') {
            if ($host !== 'localhost' && $host !== APP_HOSTNAME_INTERNAL && $host !== System::getEnv('_APP_CONSOLE_DOMAIN', '')) {
                throw new AppwriteException(AppwriteException::GENERAL_ACCESS_FORBIDDEN, 'Router protection does not allow accessing Appwrite over this domain. Please add it as custom domain to your project or disable _APP_OPTIONS_ROUTER_PROTECTION environment variable.', view: $errorView);
            }
        }

        // Act as API - no Proxy logic
        return false;
    }

    $projectId = $rule->getAttribute('projectId');
    $project = Authorization::skip(
        fn () => $dbForPlatform->getDocument('projects', $projectId)
    );

    if (!$project->isEmpty() && $project->getId() !== 'console') {
        $accessedAt = $project->getAttribute('accessedAt', 0);
        if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $accessedAt) {
            $project->setAttribute('accessedAt', DateTime::now());
            Authorization::skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $project));
        }
    }

    if (array_key_exists('proxy', $project->getAttribute('services', []))) {
        $status = $project->getAttribute('services', [])['proxy'];
        if (!$status) {
            throw new AppwriteException(AppwriteException::GENERAL_SERVICE_DISABLED, view: $errorView);
        }
    }

    // Skip Appwrite Router for ACME challenge. Nessessary for certificate generation
    $path = ($swooleRequest->server['request_uri'] ?? '/');
    if (\str_starts_with($path, '/.well-known/acme-challenge')) {
        return false;
    }

    $type = $rule->getAttribute('type', '');

    if ($type === 'deployment') {
        if (System::getEnv('_APP_OPTIONS_ROUTER_FORCE_HTTPS', 'disabled') === 'enabled') { // Force HTTPS
            if ($request->getProtocol() !== 'https' && $request->getHostname() !== APP_HOSTNAME_INTERNAL) {
                if ($request->getMethod() !== Request::METHOD_GET) {
                    throw new AppwriteException(AppwriteException::GENERAL_PROTOCOL_UNSUPPORTED, 'Method unsupported over HTTP. Please use HTTPS instead.', view: $errorView);
                }
                return $response->redirect('https://' . $request->getHostname() . $request->getURI());
            }
        }

        /** @var Database $dbForProject */
        $dbForProject = $getProjectDB($project);

        /** @var Document $deployment */
        if (!empty($rule->getAttribute('deploymentId', ''))) {
            $deployment = Authorization::skip(fn () => $dbForProject->getDocument('deployments', $rule->getAttribute('deploymentId')));
        } else {
            // 1.6.x DB schema compatibility
            // TODO: Make sure deploymentId is never empty, and remove this code

            // Check if site or function; should never be site, but better safe than sorry
            // Attempts to use attribute from both schemas (1.6 and 1.7)
            $resourceType = $rule->getAttribute('deploymentResourceType', $rule->getAttribute('resourceType', ''));

            // ID of site or function
            $resourceId = $rule->getAttribute('deploymentResourceId', '');

            // Document of site or function
            $resource = $resourceType === 'function' ?
                Authorization::skip(fn () => $dbForProject->getDocument('functions', $resourceId)) :
                Authorization::skip(fn () => $dbForProject->getDocument('sites', $resourceId));

            // ID of active deployments
            // Attempts to use attribute from both schemas (1.6 and 1.7)
            $activeDeploymentId = $resource->getAttribute('deploymentId', $resource->getAttribute('deployment', ''));

            // Get deployment document, as intended originally
            $deployment = Authorization::skip(fn () => $dbForProject->getDocument('deployments', $activeDeploymentId));
        }

        if ($deployment->getAttribute('resourceType', '') === 'functions') {
            $type = 'function';
        } elseif ($deployment->getAttribute('resourceType', '') === 'sites') {
            $type = 'site';
        }

        if ($deployment->isEmpty()) {
            $resourceType = $rule->getAttribute('deploymentResourceType', '');
            $resourceId = $rule->getAttribute('deploymentResourceId', '');
            $type = ($resourceType === 'site') ? 'sites' : 'functions';
            $exception = new AppwriteException(AppwriteException::DEPLOYMENT_NOT_FOUND, view: $errorView);
            $exception->addCTA('View deployments', $url . '/console/project-' . $projectId . '/' . $type . '/' . $resourceType . '-' . $resourceId);
            throw $exception;
        }

        $resource = $type === 'function' ?
            Authorization::skip(fn () => $dbForProject->getDocument('functions', $deployment->getAttribute('resourceId', ''))) :
            Authorization::skip(fn () => $dbForProject->getDocument('sites', $deployment->getAttribute('resourceId', '')));

        $isPreview = $type === 'function' ? false : ($rule->getAttribute('trigger', '') !== 'manual');

        $path = ($swooleRequest->server['request_uri'] ?? '/');
        $query = ($swooleRequest->server['query_string'] ?? '');
        if (!empty($query)) {
            $path .= '?' . $query;
        }

        $protocol = $request->getProtocol();

        /**
            Ensure preview authorization
            - Authorization is skippable for tests, and build screenshot
            - If cookie is not sent by client -> not authorized
            - If JWT in cookie is invalid or expired -> not authorized
            - If user is blocked or removed -> not authorized
            - If user's session is removed or expired -> not authorized
            - If user is not member of team of this deployment -> not authorized
            - If not authorized, redirect to Console redirect UI
            - If authorized, continue as if auth was not required
        */
        $requirePreview = \is_null($apiKey) || !$apiKey->isPreviewAuthDisabled();
        if ($isPreview && $requirePreview) {
            $cookie = $request->getCookie(Auth::$cookieNamePreview, '');
            $authorized = false;

            // Security checks to mark authorized true
            if (!empty($cookie)) {
                $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 3600, 0);

                $payload = [];
                try {
                    $payload = $jwt->decode($cookie);
                } catch (JWTException $error) {
                    // Authorized remains false
                }

                $userExists = false;
                $userId = $payload['userId'] ?? '';
                if (!empty($userId)) {
                    $user = Authorization::skip(fn () => $dbForPlatform->getDocument('users', $userId));
                    if (!$user->isEmpty() && $user->getAttribute('status', false)) {
                        $userExists = true;
                    }
                }

                $sessionExists = false;
                $jwtSessionId = $payload['sessionId'] ?? '';
                if (!empty($jwtSessionId) && !empty($user->find('$id', $jwtSessionId, 'sessions'))) {
                    $sessionExists = true;
                }

                $membershipExists = false;
                $project = Authorization::skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
                if (!$project->isEmpty() && isset($user)) {
                    $teamId = $project->getAttribute('teamId', '');
                    $membership = $user->find('teamId', $teamId, 'memberships');
                    if (!empty($membership)) {
                        $membershipExists = true;
                    }
                }

                if ($userExists && $sessionExists && $membershipExists) {
                    $authorized = true;
                }
            }

            if (!$authorized) {
                $url = (System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https') . "://" . System::getEnv('_APP_DOMAIN');
                $response
                    ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->addHeader('Pragma', 'no-cache')
                    ->redirect($url . '/console/auth/preview?'
                        . \http_build_query([
                            'projectId' => $projectId,
                            'origin' => $protocol . '://' . $host,
                            'path' => $path
                        ]));
                return true;
            }
        }

        $body = $swooleRequest->getContent() ?? '';
        $method = $swooleRequest->server['request_method'];

        $requestHeaders = $request->getHeaders();

        if ($resource->isEmpty() || !$resource->getAttribute('enabled')) {
            if ($type === 'functions') {
                throw new AppwriteException(AppwriteException::FUNCTION_NOT_FOUND, view: $errorView);
            } else {
                throw new AppwriteException(AppwriteException::SITE_NOT_FOUND, view: $errorView);
            }
        }

        if ($isResourceBlocked($project, $type === 'function' ? RESOURCE_TYPE_FUNCTIONS : RESOURCE_TYPE_SITES, $resource->getId())) {
            throw new AppwriteException(AppwriteException::GENERAL_RESOURCE_BLOCKED, view: $errorView);
        }

        $version = match ($type) {
            'function' => $resource->getAttribute('version', 'v2'),
            'site' => 'v5',
        };

        $runtimes = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', []);
        $spec = Config::getParam('specifications')[$resource->getAttribute('specification', APP_COMPUTE_SPECIFICATION_DEFAULT)];

        $runtime = match ($type) {
            'function' => $runtimes[$resource->getAttribute('runtime')] ?? null,
            'site' => $runtimes[$resource->getAttribute('buildRuntime')] ?? null,
            default => null
        };

        // Static site enforced runtime
        if ($deployment->getAttribute('adapter', '') === 'static') {
            $runtime = $runtimes['static-1'] ?? null;
        }

        if (\is_null($runtime)) {
            throw new AppwriteException(AppwriteException::FUNCTION_RUNTIME_UNSUPPORTED, 'Runtime "' . $resource->getAttribute('runtime', '') . '" is not supported', view: $errorView);
        }

        $allowAnyStatus = !\is_null($apiKey) && $apiKey->isDeploymentStatusIgnored();
        if (!$allowAnyStatus && $deployment->getAttribute('status') !== 'ready') {
            $status = $deployment->getAttribute('status');

            switch ($status) {
                case 'failed':
                    $exception = new AppwriteException(AppwriteException::BUILD_FAILED, view: $errorView);
                    $ctaUrl = '/console/project-' . $project->getId() . '/sites/site-' . $resource->getId() . '/deployments/deployment-' . $deployment->getId();
                    $exception->addCTA('View logs', $url . $ctaUrl);
                    break;
                case 'canceled':
                    $exception = new AppwriteException(AppwriteException::BUILD_CANCELED, view: $errorView);
                    $ctaUrl = '/console/project-' . $project->getId() . '/sites/site-' . $resource->getId() . '/deployments';
                    $exception->addCTA('View deployments', $url . $ctaUrl);
                    break;
                default:
                    $exception = new AppwriteException(AppwriteException::BUILD_NOT_READY, view: $errorView);
                    $ctaUrl = '/console/project-' . $project->getId() . '/sites/site-' . $resource->getId() . '/deployments/deployment-' . $deployment->getId();
                    $exception->addCTA('Reload', '/');
                    $exception->addCTA('View logs', $url . $ctaUrl);
                    break;
            }
            throw $exception;
        }

        if ($type === 'function') {
            $permissions = $resource->getAttribute('execute');
            if (!(\in_array('any', $permissions)) && !(\in_array('guests', $permissions))) {
                $exception = new AppwriteException(AppwriteException::FUNCTION_EXECUTE_PERMISSION_MISSING, view: $errorView);
                $exception->addCTA('View settings', $url . '/console/project-' . $project->getId() . '/functions/function-' . $resource->getId() . '/settings');
                throw $exception;
            }
        }

        $headers = \array_merge([], $requestHeaders);
        $headers['x-appwrite-user-id'] = '';
        $headers['x-appwrite-country-code'] = '';
        $headers['x-appwrite-continent-code'] = '';
        $headers['x-appwrite-continent-eu'] = 'false';

        $jwtExpiry = $resource->getAttribute('timeout', 900);
        $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $jwtExpiry, 0);
        $jwtKey = $jwtObj->encode([
            'projectId' => $project->getId(),
            'scopes' => $resource->getAttribute('scopes', [])
        ]);
        $headers['x-appwrite-key'] = API_KEY_DYNAMIC . '_' . $jwtKey;
        $headers['x-appwrite-trigger'] = 'http';
        $headers['x-appwrite-user-jwt'] = '';

        $ip = $headers['x-real-ip'] ?? '';
        if (!empty($ip)) {
            $record = $geodb->get($ip);

            if ($record) {
                $eu = Config::getParam('locale-eu');

                $headers['x-appwrite-country-code'] = $record['country']['iso_code'] ?? '';
                $headers['x-appwrite-continent-code'] = $record['continent']['code'] ?? '';
                $headers['x-appwrite-continent-eu'] = (\in_array($record['country']['iso_code'], $eu)) ? 'true' : 'false';
            }
        }

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
            'resourceInternalId' => $resource->getInternalId(),
            'resourceId' => $resource->getId(),
            'deploymentInternalId' => $deployment->getInternalId(),
            'deploymentId' => $deployment->getId(),
            'responseStatusCode' => 0,
            'responseHeaders' => [],
            'requestPath' => $path,
            'requestMethod' => $method,
            'requestHeaders' => $headersFiltered,
            'errors' => '',
            'logs' => '',
            'duration' => 0.0,
        ]);

        if ($type === 'function') {
            $execution->setAttribute('resourceType', 'functions');
            $execution->setAttribute('trigger', 'http'); // http / schedule / event
            $execution->setAttribute('status', 'processing'); // waiting / processing / completed / failed

            $queueForEvents
                ->setParam('functionId', $resource->getId())
                ->setParam('executionId', $execution->getId())
                ->setContext('function', $resource);
        } elseif ($type === 'site') {
            $execution->setAttribute('resourceType', 'sites');

            $queueForEvents
                ->setParam('siteId', $resource->getId())
                ->setParam('executionId', $execution->getId())
                ->setContext('site', $resource);
        }

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
        foreach ($resource->getAttribute('varsProject', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }

        // Function vars
        foreach ($resource->getAttribute('vars', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
        $hostname = System::getEnv('_APP_DOMAIN');
        $endpoint = $protocol . '://' . $hostname . "/v1";

        // Appwrite vars
        if ($type === 'function') {
            $vars = \array_merge($vars, [
                'APPWRITE_FUNCTION_API_ENDPOINT' => $endpoint,
                'APPWRITE_FUNCTION_ID' => $resource->getId(),
                'APPWRITE_FUNCTION_NAME' => $resource->getAttribute('name'),
                'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
                'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
                'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
                'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
                'APPWRITE_FUNCTION_CPUS' => $spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT,
                'APPWRITE_FUNCTION_MEMORY' => $spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT,
            ]);
        } elseif ($type === 'site') {
            $vars = \array_merge($vars, [
                'APPWRITE_SITE_API_ENDPOINT' => $endpoint,
                'APPWRITE_SITE_ID' => $resource->getId(),
                'APPWRITE_SITE_NAME' => $resource->getAttribute('name'),
                'APPWRITE_SITE_DEPLOYMENT' => $deployment->getId(),
                'APPWRITE_SITE_PROJECT_ID' => $project->getId(),
                'APPWRITE_SITE_RUNTIME_NAME' => $runtime['name'] ?? '',
                'APPWRITE_SITE_RUNTIME_VERSION' => $runtime['version'] ?? '',
                'APPWRITE_SITE_CPUS' => $spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT,
                'APPWRITE_SITE_MEMORY' => $spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT,
            ]);
        }

        $vars = \array_merge($vars, [
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

        // SPA fallbackFile override
        if ($deployment->getAttribute('adapter', '') === 'static' && $deployment->getAttribute('fallbackFile', '') !== '') {
            $vars['OPEN_RUNTIMES_STATIC_FALLBACK'] = $deployment->getAttribute('fallbackFile', '');
        }

        /** Execute function */
        try {
            $version = match ($type) {
                'function' => $resource->getAttribute('version', 'v2'),
                'site' => 'v5',
            };
            $entrypoint = match ($type) {
                'function' => $deployment->getAttribute('entrypoint', ''),
                'site' => '',
            };

            if ($type === 'function') {
                $runtimeEntrypoint = match ($version) {
                    'v2' => '',
                    default => 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $runtime['startCommand'] . '"'
                };
            } elseif ($type === 'site') {
                $frameworks = Config::getParam('frameworks', []);
                $framework = $frameworks[$resource->getAttribute('framework', '')] ?? null;

                $startCommand = $runtime['startCommand'];

                if (!is_null($framework)) {
                    $adapter = ($framework['adapters'] ?? [])[$deployment->getAttribute('adapter', '')] ?? null;
                    if (!is_null($adapter) && isset($adapter['startCommand'])) {
                        $startCommand = $adapter['startCommand'];
                    }
                }

                $runtimeEntrypoint = 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $startCommand . '"';
            }

            $entrypoint = match ($type) {
                'function' => $deployment->getAttribute('entrypoint', ''),
                'site' => '',
            };

            $executionResponse = $executor->createExecution(
                projectId: $project->getId(),
                deploymentId: $deployment->getId(),
                body: \strlen($body) > 0 ? $body : null,
                variables: $vars,
                timeout: $resource->getAttribute('timeout', 30),
                image: $runtime['image'],
                source: $deployment->getAttribute('buildPath', ''),
                entrypoint: $entrypoint,
                version: $version,
                path: $path,
                method: $method,
                headers: $headers,
                runtimeEntrypoint: $runtimeEntrypoint,
                cpus: $spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT,
                memory: $spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT,
                logging: $resource->getAttribute('logging', true),
                requestTimeout: 30
            );

            // Branded 404 override
            $isResponseBranded = false;
            if ($executionResponse['statusCode'] === 404 && $deployment->getAttribute('adapter', '') === 'static') {
                $layout = new View(__DIR__ . '/../views/general/404.phtml');
                $executionResponse['body'] = $layout->render();
                $executionResponse['headers']['content-length'] = \strlen($executionResponse['body']);
                $isResponseBranded = true;
            }

            // Branded banner for previews
            if (!$isResponseBranded) {
                if (\is_null($apiKey) || $apiKey->isBannerDisabled() === false) {
                    $transformation = new Transformation();
                    $transformation->addAdapter(new Preview());
                    $transformation->setInput($executionResponse['body']);
                    $transformation->setTraits($executionResponse['headers']);
                    if ($isPreview && $transformation->transform()) {
                        $executionResponse['body'] = $transformation->getOutput();

                        foreach ($executionResponse['headers'] as $key => $value) {
                            if (\strtolower($key) === 'content-length') {
                                $executionResponse['headers'][$key] = \strlen($executionResponse['body']);
                            }
                        }
                    }
                }
            }

            // Branded error pages (when developer left body empty)
            if ($executionResponse['statusCode'] >= 400 && empty($executionResponse['body'])) {
                $layout = new View($errorView);
                $layout
                    ->setParam('title', $project->getAttribute('name') . ' - Error')
                    ->setParam('type', 'proxy_error_override')
                    ->setParam('code', $executionResponse['statusCode']);

                $executionResponse['body'] = $layout->render();
                foreach ($executionResponse['headers'] as $key => $value) {
                    if (\strtolower($key) === 'content-length') {
                        $executionResponse['headers'][$key] = \strlen($executionResponse['body']);
                    } elseif (\strtolower($key) === 'content-type') {
                        $executionResponse['headers'][$key] = 'text/html';
                    }
                }
            }

            $headersFiltered = [];
            foreach ($executionResponse['headers'] as $key => $value) {
                if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_RESPONSE)) {
                    $headersFiltered[] = ['name' => $key, 'value' => $value];
                }
            }

            /** Update execution status */
            $status = $executionResponse['statusCode'] >= 500 ? 'failed' : 'completed';
            $execution->setAttribute('status', $status);
            $execution->setAttribute('logs', $executionResponse['logs']);
            $execution->setAttribute('errors', $executionResponse['errors']);
            $execution->setAttribute('responseStatusCode', $executionResponse['statusCode']);
            $execution->setAttribute('responseHeaders', $headersFiltered);
            $execution->setAttribute('duration', $executionResponse['duration']);
        } catch (\Throwable $th) {
            $durationEnd = \microtime(true);

            $execution
                ->setAttribute('duration', $durationEnd - $durationStart)
                ->setAttribute('responseStatusCode', 500);

            if ($type === 'function') {
                $execution
                    ->setAttribute('status', 'failed')
                    ->setAttribute('errors', $th->getMessage() . '\nError Code: ' . $th->getCode());
            }
            Console::error($th->getMessage());

            if ($th instanceof AppwriteException) {
                throw $th;
            }
        } finally {
            if ($type === 'function') {
                $queueForFunctions
                    ->setType(Func::TYPE_ASYNC_WRITE)
                    ->setExecution($execution)
                    ->setProject($project)
                    ->trigger();
            } elseif ($type === 'site') { // TODO: Move it to logs worker later
                $dbForProject->createDocument('executions', $execution);
            }
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

            if (\strtolower($header['name']) === 'transfer-encoding') {
                continue;
            }

            $response->addHeader(\strtolower($header['name']), $header['value']);
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

        if (!empty($apiKey) && !empty($apiKey->getDisabledMetrics())) {
            foreach ($apiKey->getDisabledMetrics() as $key) {
                $queueForStatsUsage->disableMetric($key);
            }
        }

        $metricTypeExecutions = str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_EXECUTIONS);
        $metricTypeIdExecutions = str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS);
        $metricTypeExecutionsCompute = str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_EXECUTIONS_COMPUTE);
        $metricTypeIdExecutionsCompute = str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_COMPUTE);
        $metricTypeExecutionsMbSeconds = str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_EXECUTIONS_MB_SECONDS);
        $metricTypeIdExecutionsMBSeconds = str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getInternalId()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_MB_SECONDS);
        if ($deployment->getAttribute('resourceType') === 'sites') {
            $queueForStatsUsage
                ->disableMetric(METRIC_NETWORK_REQUESTS)
                ->disableMetric(METRIC_NETWORK_INBOUND)
                ->disableMetric(METRIC_NETWORK_OUTBOUND);
            if ($resource->getAttribute('adapter') !== 'ssr') {
                $queueForStatsUsage
                    ->disableMetric(METRIC_EXECUTIONS)
                    ->disableMetric(METRIC_EXECUTIONS_COMPUTE)
                    ->disableMetric(METRIC_EXECUTIONS_MB_SECONDS)
                    ->disableMetric($metricTypeExecutions)
                    ->disableMetric($metricTypeIdExecutions)
                    ->disableMetric($metricTypeExecutionsCompute)
                    ->disableMetric($metricTypeIdExecutionsCompute)
                    ->disableMetric($metricTypeExecutionsMbSeconds)
                    ->disableMetric($metricTypeIdExecutionsMBSeconds);
            }

            $queueForStatsUsage
                ->addMetric(METRIC_SITES_REQUESTS, 1)
                ->addMetric(METRIC_SITES_INBOUND, $request->getSize() + $fileSize)
                ->addMetric(METRIC_SITES_OUTBOUND, $response->getSize())
                ->addMetric(str_replace('{siteInternalId}', $resource->getInternalId(), METRIC_SITES_ID_REQUESTS), 1)
                ->addMetric(str_replace('{siteInternalId}', $resource->getInternalId(), METRIC_SITES_ID_INBOUND), $request->getSize() + $fileSize)
                ->addMetric(str_replace('{siteInternalId}', $resource->getInternalId(), METRIC_SITES_ID_OUTBOUND), $response->getSize())
            ;
        }


        $compute = (int)($execution->getAttribute('duration') * 1000);
        $mbSeconds = (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $execution->getAttribute('duration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT));
        $queueForStatsUsage
            ->addMetric(METRIC_NETWORK_REQUESTS, 1)
            ->addMetric(METRIC_NETWORK_INBOUND, $request->getSize() + $fileSize)
            ->addMetric(METRIC_NETWORK_OUTBOUND, $response->getSize())
            ->addMetric(METRIC_EXECUTIONS, 1)
            ->addMetric($metricTypeExecutions, 1)
            ->addMetric($metricTypeIdExecutions, 1)
            ->addMetric(METRIC_EXECUTIONS_COMPUTE, $compute) // per project
            ->addMetric($metricTypeExecutionsCompute, $compute) // per function
            ->addMetric($metricTypeIdExecutionsCompute, $compute) // per function
            ->addMetric(METRIC_EXECUTIONS_MB_SECONDS, $mbSeconds)
            ->addMetric($metricTypeExecutionsMbSeconds, $mbSeconds)
            ->addMetric($metricTypeIdExecutionsMBSeconds, $mbSeconds)
            ->setProject($project)
            ->trigger();

        return true;
    } elseif ($type === 'api') {
        return false;
    } elseif ($type === 'redirect') {
        $url = $rule->getAttribute('redirectUrl', '');

        $query = ($swooleRequest->server['query_string'] ?? '');
        if (!empty($query)) {
            $url .= '?' . $query;
        }

        $response->redirect($url, \intval($rule->getAttribute('redirectStatusCode', 301)));
        return true;
    } else {
        throw new AppwriteException(AppwriteException::GENERAL_SERVER_ERROR, 'Unknown resource type ' . $type, view: $errorView);
    }

    return false;
}

App::init()
    ->groups(['api'])
    ->inject('project')
    ->inject('mode')
    ->action(function (Document $project, string $mode) {
        if ($mode === APP_MODE_ADMIN && $project->getId() === 'console') {
            throw new AppwriteException(AppwriteException::GENERAL_BAD_REQUEST, 'Admin mode is not allowed for console project');
        }
    });

App::init()
    ->groups(['database', 'functions', 'sites', 'messaging'])
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
    ->inject('geodb')
    ->inject('queueForStatsUsage')
    ->inject('queueForEvents')
    ->inject('queueForCertificates')
    ->inject('queueForFunctions')
    ->inject('executor')
    ->inject('isResourceBlocked')
    ->inject('previewHostname')
    ->inject('devKey')
    ->inject('apiKey')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Document $console, Document $project, Database $dbForPlatform, callable $getProjectDB, Locale $locale, array $localeCodes, array $clients, Reader $geodb, StatsUsage $queueForStatsUsage, Event $queueForEvents, Certificate $queueForCertificates, Func $queueForFunctions, Executor $executor, callable $isResourceBlocked, string $previewHostname, Document $devKey, ?Key $apiKey) {
        /*
        * Appwrite Router
        */
        $host = $request->getHostname() ?? '';
        $mainDomain = System::getEnv('_APP_DOMAIN', '');
        // Only run Router when external domain
        if ($host !== $mainDomain || !empty($previewHostname)) {
            if (router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $queueForEvents, $queueForStatsUsage, $queueForFunctions, $executor, $geodb, $isResourceBlocked, $previewHostname, $apiKey)) {
                $utopia->getRoute()?->label('router', true);
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
            if (version_compare($requestFormat, '1.7.0', '<')) {
                $request->addFilter(new RequestV19());
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

                    $owner = '';
                    $functionsDomainFallback = System::getEnv('_APP_DOMAIN_FUNCTIONS_FALLBACK', '');
                    $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');
                    $siteDomain = System::getEnv('_APP_DOMAIN_SITES', '');
                    if (!empty($functionsDomainFallback) && \str_ends_with($host, $functionsDomainFallback)) {
                        $functionsDomain = $functionsDomainFallback;
                    }

                    if (
                        (!empty($functionsDomain) && \str_ends_with($domain->get(), $functionsDomain)) ||
                        (!empty($siteDomain) && \str_ends_with($domain->get(), $siteDomain))
                    ) {
                        $owner = 'Appwrite';
                    }

                    if ($domainDocument->isEmpty()) {
                        $ruleId = System::getEnv('_APP_RULES_FORMAT') === 'md5' ? md5($domain->get()) : ID::unique();
                        $domainDocument = new Document([
                            // TODO: @christyjacob remove once we migrate the rules in 1.7.x
                            '$id' => $ruleId,
                            'domain' => $domain->get(),
                            'type' => 'api',
                            'status' => 'verifying',
                            'projectId' => $console->getId(),
                            'projectInternalId' => $console->getInternalId(),
                            'search' => implode(' ', [$ruleId, $domain->get()]),
                            'owner' => $owner,
                            'region' => $console->getAttribute('region')
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
        } elseif (!empty($origin)) {
            // Auto-allow domains with linked rule
            if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
                $rule = Authorization::skip(fn () => $dbForPlatform->getDocument('rules', md5($origin ?? '')));
            } else {
                $rule = Authorization::skip(
                    fn () => $dbForPlatform->find('rules', [
                        Query::equal('domain', [$origin]),
                        Query::limit(1)
                    ])
                )[0] ?? new Document();
            }

            if (!$rule->isEmpty() && $rule->getAttribute('projectInternalId') === $project->getInternalId()) {
                $refDomainOrigin = $origin;
            }
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
            if (version_compare($responseFormat, '1.7.0', '<')) {
                $response->addFilter(new ResponseV19());
            }
            if (version_compare($responseFormat, APP_VERSION_STABLE, '>')) {
                $response->addHeader('X-Appwrite-Warning', "The current SDK is built for Appwrite " . $responseFormat . ". However, the current Appwrite server version is " . APP_VERSION_STABLE . ". Please downgrade your SDK to match the Appwrite version: https://appwrite.io/docs/sdks");
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

        if (!$devKey->isEmpty()) {
            $response->addHeader('Access-Control-Allow-Origin', '*');
        }

        /*
        * Validate Client Domain - Check to avoid CSRF attack
        *  Adding Appwrite API domains to allow XDOMAIN communication
        *  Skip this check for non-web platforms which are not required to send an origin header
        */
        $origin = $request->getOrigin($request->getReferer(''));
        $originValidator = new Origin(\array_merge($project->getAttribute('platforms', []), $console->getAttribute('platforms', [])));

        if (
            !$originValidator->isValid($origin)
            && $devKey->isEmpty()
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
    ->inject('queueForStatsUsage')
    ->inject('queueForFunctions')
    ->inject('executor')
    ->inject('geodb')
    ->inject('isResourceBlocked')
    ->inject('previewHostname')
    ->inject('project')
    ->inject('devKey')
    ->inject('apiKey')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Database $dbForPlatform, callable $getProjectDB, Event $queueForEvents, StatsUsage $queueForStatsUsage, Func $queueForFunctions, Executor $executor, Reader $geodb, callable $isResourceBlocked, string $previewHostname, Document $project, Document $devKey, ?Key $apiKey) {
        /*
        * Appwrite Router
        */
        $host = $request->getHostname() ?? '';
        $mainDomain = System::getEnv('_APP_DOMAIN', '');
        // Only run Router when external domain
        if ($host !== $mainDomain || !empty($previewHostname)) {
            if (router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $queueForEvents, $queueForStatsUsage, $queueForFunctions, $executor, $geodb, $isResourceBlocked, $previewHostname, $apiKey)) {
                $utopia->getRoute()?->label('router', true);
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

        if (!$devKey->isEmpty()) {
            $response->addHeader('Access-Control-Allow-Origin', '*');
        }

        /** OPTIONS requests in utopia do not execute shutdown handlers, as a result we need to track the OPTIONS requests explicitly
         * @see https://github.com/utopia-php/http/blob/0.33.16/src/App.php#L825-L855
         */
        $queueForStatsUsage
            ->addMetric(METRIC_NETWORK_REQUESTS, 1)
            ->addMetric(METRIC_NETWORK_INBOUND, $request->getSize())
            ->addMetric(METRIC_NETWORK_OUTBOUND, $response->getSize())
            ->setProject($project)
            ->trigger();
    });

App::error()
    ->inject('error')
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('logger')
    ->inject('log')
    ->inject('queueForStatsUsage')
    ->inject('devKey')
    ->action(function (Throwable $error, App $utopia, Request $request, Response $response, Document $project, ?Logger $logger, Log $log, StatsUsage $queueForStatsUsage) {
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
            case 'Utopia\Database\Exception\Authorization':
                $error = new AppwriteException(AppwriteException::USER_UNAUTHORIZED);
                break;
            case 'Utopia\Database\Exception\Timeout':
                $error = new AppwriteException(AppwriteException::DATABASE_TIMEOUT, previous: $error);
                break;
        }

        $code = $error->getCode();
        $message = $error->getMessage();

        if ($error instanceof AppwriteException) {
            $publish = $error->isPublishable();
        } else {
            $publish = $error->getCode() === 0 || $error->getCode() >= 500;
        }

        $providerConfig = System::getEnv('_APP_EXPERIMENT_LOGGING_CONFIG', '');
        if (!empty($providerConfig) && $error->getCode() >= 400 && $error->getCode() < 500) {
            // Register error logger
            try {
                $loggingProvider = new DSN($providerConfig);
                $providerName = $loggingProvider->getScheme();

                if (!empty($providerName) && $providerName === 'sentry') {
                    $key = $loggingProvider->getPassword();
                    $projectId = $loggingProvider->getUser() ?? '';
                    $host = 'https://' . $loggingProvider->getHost();
                    $sampleRate = $loggingProvider->getParam('sample', 0.01);

                    $adapter = new Sentry($projectId, $key, $host);
                    $logger = new Logger($adapter);
                    $logger->setSample($sampleRate);
                    $publish = true;
                } else {
                    throw new \Exception('Invalid experimental logging provider');
                }
            } catch (\Throwable $th) {
                Console::warning('Failed to initialize logging provider: ' . $th->getMessage());
            }
        }

        /**
         * If its not a publishable error, track usage stats. Publishable errors are >= 500 or those explicitly marked as publish=true in errors.php
         */
        if (!$publish && $project->getId() !== 'console') {
            if (!Auth::isPrivilegedUser(Authorization::getRoles())) {
                $fileSize = 0;
                $file = $request->getFiles('file');
                if (!empty($file)) {
                    $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];
                }

                $queueForStatsUsage
                    ->addMetric(METRIC_NETWORK_REQUESTS, 1)
                    ->addMetric(METRIC_NETWORK_INBOUND, $request->getSize() + $fileSize)
                    ->addMetric(METRIC_NETWORK_OUTBOUND, $response->getSize());
            }

            $queueForStatsUsage
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
            $log->addTag('url', $request->getURI());
            $log->addTag('verboseType', get_class($error));
            $log->addTag('code', $error->getCode());
            $log->addTag('projectId', $project->getId());
            $log->addTag('hostname', $request->getHostname());
            $log->addTag('locale', (string)$request->getParam('locale', $request->getHeader('x-appwrite-locale', '')));

            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('roles', Authorization::getRoles());

            $action = 'UNKNOWN_NAMESPACE.UNKNOWN.METHOD';
            if (!empty($sdk)) {
                /** @var Appwrite\SDK\Method $sdk */
                $action = $sdk->getNamespace() . '.' . $sdk->getMethodName();
            }

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

        $output = App::isDevelopment() ? [
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

        $template = $error->getView() ?? (($route) ? $route->getLabel('error', null) : null);

        // TODO: Ideally use group 'api' here, but all wildcard routes seem to have 'api' at the moment
        if (!\str_starts_with($route->getPath(), '/v1')) {
            $template = __DIR__ . '/../views/general/error.phtml';
        }

        if (!empty($template)) {
            $layout = new View($template);

            $layout
                ->setParam('title', $project->getAttribute('name') . ' - Error')
                ->setParam('development', App::isDevelopment())
                ->setParam('projectName', $project->getAttribute('name'))
                ->setParam('projectURL', $project->getAttribute('url'))
                ->setParam('message', $output['message'] ?? '')
                ->setParam('type', $output['type'] ?? '')
                ->setParam('code', $output['code'] ?? '')
                ->setParam('trace', $output['trace'] ?? [])
                ->setParam('exception', $error);

            $response->html($layout->render());
            return;
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
    ->inject('queueForStatsUsage')
    ->inject('queueForFunctions')
    ->inject('executor')
    ->inject('geodb')
    ->inject('isResourceBlocked')
    ->inject('previewHostname')
    ->inject('apiKey')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Database $dbForPlatform, callable $getProjectDB, Event $queueForEvents, StatsUsage $queueForStatsUsage, Func $queueForFunctions, Executor $executor, Reader $geodb, callable $isResourceBlocked, string $previewHostname, ?Key $apiKey) {
        $host = $request->getHostname() ?? '';
        $mainDomain = System::getEnv('_APP_DOMAIN', '');

        if (($host === $mainDomain || $host === 'localhost') && empty($previewHostname)) {
            $template = new View(__DIR__ . '/../views/general/robots.phtml');
            $response->text($template->render(false));
        } else {
            if (router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $queueForEvents, $queueForStatsUsage, $queueForFunctions, $executor, $geodb, $isResourceBlocked, $previewHostname, $apiKey)) {
                $utopia->getRoute()?->label('router', true);
            }
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
    ->inject('queueForStatsUsage')
    ->inject('queueForFunctions')
    ->inject('executor')
    ->inject('geodb')
    ->inject('isResourceBlocked')
    ->inject('previewHostname')
    ->inject('apiKey')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Database $dbForPlatform, callable $getProjectDB, Event $queueForEvents, StatsUsage $queueForStatsUsage, Func $queueForFunctions, Executor $executor, Reader $geodb, callable $isResourceBlocked, string $previewHostname, ?Key $apiKey) {
        $host = $request->getHostname() ?? '';
        $mainDomain = System::getEnv('_APP_DOMAIN', '');

        if (($host === $mainDomain || $host === 'localhost') && empty($previewHostname)) {
            $template = new View(__DIR__ . '/../views/general/humans.phtml');
            $response->text($template->render(false));
        } else {
            if (router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $queueForEvents, $queueForStatsUsage, $queueForFunctions, $executor, $geodb, $isResourceBlocked, $previewHostname, $apiKey)) {
                $utopia->getRoute()?->label('router', true);
            }
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
    ->label('sdk', new Method(
        namespace: 'ping',
        group: null,
        name: 'get',
        hide: true,
        description: <<<EOT
        Send a ping to project as part of onboarding.
        EOT,
        auth: [],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ANY,
            )
        ],
    ))
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->inject('queueForEvents')
    ->action(function (Response $response, Document $project, Database $dbForPlatform, Event $queueForEvents) {
        if ($project->isEmpty() || $project->getId() === 'console') {
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

// Preview authorization
App::get('/_appwrite/authorize')
    ->inject('request')
    ->inject('response')
    ->inject('previewHostname')
    ->action(function (Request $request, Response $response, string $previewHostname) {

        $host = $request->getHostname() ?? '';
        if (!empty($previewHostname)) {
            $host = $previewHostname;
        }

        $referrer = $request->getReferer();
        $protocol = \parse_url($request->getOrigin($referrer), PHP_URL_SCHEME);

        $jwt = $request->getParam('jwt', '');
        $path = $request->getParam('path', '');

        $duration = 60 * 60 * 24; // 1 day in seconds
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $response
            ->addCookie(Auth::$cookieNamePreview, $jwt, (new \DateTime($expire))->getTimestamp(), '/', $host, ('https' === $protocol), true, null)
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($protocol . '://' . $host . $path);
    });

App::wildcard()
    ->groups(['api'])
    ->label('scope', 'global')
    ->action(function () {
        throw new AppwriteException(AppwriteException::GENERAL_ROUTE_NOT_FOUND);
    });

foreach (Config::getParam('services', []) as $service) {
    if (!empty($service['controller'])) {
        include_once $service['controller'];
    }
}

// Check for any errors found while we were initialising the SDK Methods.
if (!empty(Method::getErrors())) {
    throw new \Exception('Errors found during SDK initialization:' . PHP_EOL . implode(PHP_EOL, Method::getErrors()));
}

// Modules

$platform = new Appwrite();
$platform->init(Service::TYPE_HTTP);
