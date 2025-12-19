<?php

require_once __DIR__ . '/../init.php';

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Auth\Key;
use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Network\Cors;
use Appwrite\Platform\Appwrite;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Transformation\Adapter\Preview;
use Appwrite\Transformation\Transformation;
use Appwrite\Utopia\Database\Documents\User as DBUser;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Request\Filters\V16 as RequestV16;
use Appwrite\Utopia\Request\Filters\V17 as RequestV17;
use Appwrite\Utopia\Request\Filters\V18 as RequestV18;
use Appwrite\Utopia\Request\Filters\V19 as RequestV19;
use Appwrite\Utopia\Request\Filters\V20 as RequestV20;
use Appwrite\Utopia\Request\Filters\V21 as RequestV21;
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
use Utopia\Database\Exception\Duplicate;
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
use Utopia\Validator;
use Utopia\Validator\Text;

Config::setParam('domainVerification', false);
Config::setParam('cookieDomain', 'localhost');
Config::setParam('cookieSamesite', Response::COOKIE_SAMESITE_NONE);

function router(App $utopia, Database $dbForPlatform, callable $getProjectDB, SwooleRequest $swooleRequest, Request $request, Response $response, Log $log, Event $queueForEvents, StatsUsage $queueForStatsUsage, Func $queueForFunctions, Executor $executor, Reader $geodb, callable $isResourceBlocked, array $platform, string $previewHostname, Authorization $authorization, ?Key $apiKey)
{
    $host = $request->getHostname() ?? '';
    if (!empty($previewHostname)) {
        $host = $previewHostname;
    }

    // TODO: (@Meldiron) Remove after 1.7.x migration
    if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
        $rule = $authorization->skip(fn () => $dbForPlatform->getDocument('rules', md5($host)));
    } else {
        $rule = $authorization->skip(
            fn () => $dbForPlatform->find('rules', [
                Query::equal('domain', [$host]),
                Query::limit(1)
            ])
        )[0] ?? new Document();
    }

    $errorView = __DIR__ . '/../views/general/error.phtml';
    $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
    $url = $protocol . '://' . $platform['consoleHostname'];
    $platformHostnames = $platform['hostnames'] ?? [];

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

        if (!in_array($host, $platformHostnames)) {
            throw new AppwriteException(AppwriteException::GENERAL_ACCESS_FORBIDDEN, 'Router protection does not allow accessing Appwrite over this domain. Please add it as custom domain to your project or disable _APP_OPTIONS_ROUTER_PROTECTION environment variable.', view: $errorView);
        }

        // Act as API - no Proxy logic
        return false;
    }

    $projectId = $rule->getAttribute('projectId');
    $project = $authorization->skip(
        fn () => $dbForPlatform->getDocument('projects', $projectId)
    );

    if (!$project->isEmpty() && $project->getId() !== 'console') {
        $accessedAt = $project->getAttribute('accessedAt', 0);
        if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $accessedAt) {
            $project->setAttribute('accessedAt', DateTime::now());
            $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $project));
        }

        /**
         * Set projectId to update the Error hook logger, since x-appwrite-project is not available when executing custom domain function
         */
        $log->addTag('projectId', $project->getId());
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
            if ($request->getProtocol() !== 'https') {
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
            $deployment = $authorization->skip(fn () => $dbForProject->getDocument('deployments', $rule->getAttribute('deploymentId')));
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
                $authorization->skip(fn () => $dbForProject->getDocument('functions', $resourceId)) :
                $authorization->skip(fn () => $dbForProject->getDocument('sites', $resourceId));

            // ID of active deployments
            // Attempts to use attribute from both schemas (1.6 and 1.7)
            $activeDeploymentId = $resource->getAttribute('deploymentId', $resource->getAttribute('deployment', ''));

            // Get deployment document, as intended originally
            $deployment = $authorization->skip(fn () => $dbForProject->getDocument('deployments', $activeDeploymentId));
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
            $exception->addCTA('View deployments', $url . '/console/project-' . $project->getAttribute('region', 'default') . '-' . $projectId . '/' . $type . '/' . $resourceType . '-' . $resourceId);
            throw $exception;
        }

        $resource = $type === 'function' ?
            $authorization->skip(fn () => $dbForProject->getDocument('functions', $deployment->getAttribute('resourceId', ''))) :
            $authorization->skip(fn () => $dbForProject->getDocument('sites', $deployment->getAttribute('resourceId', '')));

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
            $cookie = $request->getCookie(COOKIE_NAME_PREVIEW, '');
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
                    $user = $authorization->skip(fn () => $dbForPlatform->getDocument('users', $userId));
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
                $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
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
                $url = $protocol . "://" . $platform['consoleHostname'];
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
            $region = $project->getAttribute('region', 'default');

            switch ($status) {
                case 'failed':
                    $exception = new AppwriteException(AppwriteException::BUILD_FAILED, view: $errorView);
                    $ctaUrl = '/console/project-' . $region . '-' . $project->getId() . '/sites/site-' . $resource->getId() . '/deployments/deployment-' . $deployment->getId();
                    $exception->addCTA('View logs', $url . $ctaUrl);
                    break;
                case 'canceled':
                    $exception = new AppwriteException(AppwriteException::BUILD_CANCELED, view: $errorView);
                    $ctaUrl = '/console/project-' . $region . '-' . $project->getId() . '/sites/site-' . $resource->getId() . '/deployments';
                    $exception->addCTA('View deployments', $url . $ctaUrl);
                    break;
                default:
                    $exception = new AppwriteException(AppwriteException::BUILD_NOT_READY, view: $errorView);
                    $ctaUrl = '/console/project-' . $region . '-' . $project->getId() . '/sites/site-' . $resource->getId() . '/deployments/deployment-' . $deployment->getId();
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
                $exception->addCTA('View settings', $url . '/console/project-' . $project->getAttribute('region', 'default') . '-' . $project->getId() . '/functions/function-' . $resource->getId() . '/settings');
                throw $exception;
            }
        }

        $executionId = ID::unique();

        $headers = \array_merge([], $requestHeaders);
        $headers['x-appwrite-execution-id'] = $executionId ?? '';
        $headers['x-appwrite-user-id'] = '';
        $headers['x-appwrite-country-code'] = '';
        $headers['x-appwrite-continent-code'] = '';
        $headers['x-appwrite-continent-eu'] = 'false';
        $ip = $request->getIP();
        $headers['x-appwrite-client-ip'] = $ip;

        $jwtExpiry = $resource->getAttribute('timeout', 900) + 60; // 1min extra to account for possible cold-starts
        $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $jwtExpiry, 0);
        $jwtKey = $jwtObj->encode([
            'projectId' => $project->getId(),
            'scopes' => $resource->getAttribute('scopes', [])
        ]);
        $headers['x-appwrite-key'] = API_KEY_DYNAMIC . '_' . $jwtKey;
        $headers['x-appwrite-trigger'] = 'http';
        $headers['x-appwrite-user-jwt'] = '';

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

        $execution = new Document([
            '$id' => $executionId,
            '$permissions' => [],
            'resourceInternalId' => $resource->getSequence(),
            'resourceId' => $resource->getId(),
            'deploymentInternalId' => $deployment->getSequence(),
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
        $endpoint = "$protocol://{$platform['apiHostname']}/v1";

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
            $source = $deployment->getAttribute('buildPath', '');
            $extension = str_ends_with($source, '.tar') ? 'tar' : 'tar.gz';

            $startCommand = $runtime['startCommand'];
            if ($type === 'site') {
                $frameworks = Config::getParam('frameworks', []);
                $framework = $frameworks[$resource->getAttribute('framework', '')] ?? null;

                if (!is_null($framework)) {
                    $adapter = ($framework['adapters'] ?? [])[$deployment->getAttribute('adapter', '')] ?? null;
                    if (!is_null($adapter) && isset($adapter['startCommand'])) {
                        $startCommand = $adapter['startCommand'];
                    }
                }
            }

            $runtimeEntrypoint = match ($version) {
                'v2' => '',
                default => "cp /tmp/code.$extension /mnt/code/code.$extension && nohup helpers/start.sh \"$startCommand\"",
            };

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
                source: $source,
                entrypoint: $entrypoint,
                version: $version,
                path: $path,
                method: $method,
                headers: $headers,
                runtimeEntrypoint: $runtimeEntrypoint,
                cpus: $spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT,
                memory: $spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT,
                logging: $resource->getAttribute('logging', true),
                requestTimeout: 30,
                responseFormat: Executor::RESPONSE_FORMAT_ARRAY_HEADERS
            );

            $headerOverrides = [];

            // Branded 404 override
            $isResponseBranded = false;
            if ($executionResponse['statusCode'] === 404 && $deployment->getAttribute('adapter', '') === 'static') {
                $layout = new View(__DIR__ . '/../views/general/404.phtml');
                $executionResponse['body'] = $layout->render();
                $headerOverrides['content-length'] = \strlen($executionResponse['body']);
                $isResponseBranded = true;
            }

            // Branded banner for previews
            if (!$isResponseBranded) {
                if (\is_null($apiKey) || $apiKey->isBannerDisabled() === false) {
                    $transformation = new Transformation();
                    $transformation->addAdapter(new Preview());
                    $transformation->setInput($executionResponse['body']);

                    $simpleHeaders = [];
                    foreach ($executionResponse['headers'] as $key => $value) {
                        $simpleHeaders[$key] = \is_array($value) ? \implode(', ', $value) : $value;
                    }

                    $transformation->setTraits($simpleHeaders);
                    if ($isPreview && $transformation->transform()) {
                        $executionResponse['body'] = $transformation->getOutput();
                        $headerOverrides['content-length'] = \strlen($executionResponse['body']);
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

                $headerOverrides['content-length'] = \strlen($executionResponse['body']);
                $headerOverrides['content-type'] = 'text/html';
            }

            if ($deployment->getAttribute('resourceType') === 'functions') {
                $headerOverrides['x-appwrite-execution-id'] = $execution->getId();
            } elseif ($deployment->getAttribute('resourceType') === 'sites') {
                $headerOverrides['x-appwrite-log-id'] = $execution->getId();
            }

            foreach ($headerOverrides as $key => $value) {
                if (\array_key_exists($key, $executionResponse['headers'])) {
                    if (\is_array($executionResponse['headers'][$key])) {
                        $executionResponse['headers'][$key][] = $value;
                    } else {
                        $executionResponse['headers'][$key] = [$executionResponse['headers'][$key], $value];
                    }
                } else {
                    $executionResponse['headers'][$key] = $value;
                }
            }

            $headersFiltered = [];
            foreach ($executionResponse['headers'] as $key => $value) {
                if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_RESPONSE)) {
                    $headersFiltered[] = ['name' => $key, 'value' => \is_array($value) ? \implode(', ', $value) : $value];
                }
            }

            // Truncate logs if they exceed the limit
            $maxLogLength = APP_FUNCTION_LOG_LENGTH_LIMIT;
            $logs = $executionResponse['logs'] ?? '';

            if (\is_string($logs) && \strlen($logs) > $maxLogLength) {
                $warningMessage = "[WARNING] Logs truncated. The output exceeded {$maxLogLength} characters.\n";
                $warningLength = \strlen($warningMessage);
                $maxContentLength = max(0, $maxLogLength - $warningLength);
                $logs = $warningMessage . ($maxContentLength > 0 ? \substr($logs, -$maxContentLength) : '');
            }

            // Truncate errors if they exceed the limit
            $maxErrorLength = APP_FUNCTION_ERROR_LENGTH_LIMIT;
            $errors = $executionResponse['errors'] ?? '';

            if (\is_string($errors) && \strlen($errors) > $maxErrorLength) {
                $warningMessage = "[WARNING] Errors truncated. The output exceeded {$maxErrorLength} characters.\n";
                $warningLength = \strlen($warningMessage);
                $maxContentLength = max(0, $maxErrorLength - $warningLength);
                $errors = $warningMessage . ($maxContentLength > 0 ? \substr($errors, -$maxContentLength) : '');
            }
            /** Update execution status */
            $status = $executionResponse['statusCode'] >= 500 ? 'failed' : 'completed';
            $execution->setAttribute('status', $status);
            $execution->setAttribute('logs', $logs);
            $execution->setAttribute('errors', $errors);
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
            $headers[] = ['name' => $key, 'value' => \is_array($value) ? \implode(', ', $value) : $value];
        }

        $execution->setAttribute('responseBody', $executionResponse['body'] ?? '');
        $execution->setAttribute('responseHeaders', $headers);

        $body = $execution['responseBody'] ?? '';

        $contentType = 'text/plain';
        foreach ($executionResponse['headers'] as $name => $values) {
            if (\strtolower($name) === 'content-type') {
                $contentType = \is_array($values) ? $values[0] : $values;
                continue;
            }

            if (\strtolower($name) === 'transfer-encoding') {
                continue;
            }

            if (\is_array($values)) {
                $count = 0;
                foreach ($values as $value) {
                    $override = $count === 0;
                    $response->addHeader($name, $value, override: $override);
                    $count++;
                }
            } else {
                $response->addHeader($name, $values);
            }
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
        $metricTypeIdExecutions = str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS);
        $metricTypeExecutionsCompute = str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_EXECUTIONS_COMPUTE);
        $metricTypeIdExecutionsCompute = str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_COMPUTE);
        $metricTypeExecutionsMbSeconds = str_replace(['{resourceType}'], [$deployment->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_EXECUTIONS_MB_SECONDS);
        $metricTypeIdExecutionsMBSeconds = str_replace(['{resourceType}', '{resourceInternalId}'], [$deployment->getAttribute('resourceType'), $resource->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_MB_SECONDS);
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
                ->addMetric(str_replace('{siteInternalId}', $resource->getSequence(), METRIC_SITES_ID_REQUESTS), 1)
                ->addMetric(str_replace('{siteInternalId}', $resource->getSequence(), METRIC_SITES_ID_INBOUND), $request->getSize() + $fileSize)
                ->addMetric(str_replace('{siteInternalId}', $resource->getSequence(), METRIC_SITES_ID_OUTBOUND), $response->getSize())
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
    ->inject('log')
    ->inject('project')
    ->inject('dbForPlatform')
    ->inject('getProjectDB')
    ->inject('locale')
    ->inject('localeCodes')
    ->inject('geodb')
    ->inject('queueForStatsUsage')
    ->inject('queueForEvents')
    ->inject('queueForFunctions')
    ->inject('executor')
    ->inject('platform')
    ->inject('isResourceBlocked')
    ->inject('previewHostname')
    ->inject('devKey')
    ->inject('apiKey')
    ->inject('cors')
    ->inject('authorization')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Log $log, Document $project, Database $dbForPlatform, callable $getProjectDB, Locale $locale, array $localeCodes, Reader $geodb, StatsUsage $queueForStatsUsage, Event $queueForEvents, Func $queueForFunctions, Executor $executor, array $platform, callable $isResourceBlocked, string $previewHostname, Document $devKey, ?Key $apiKey, Cors $cors, Authorization $authorization) {
        /*
        * Appwrite Router
        */
        $hostname = $request->getHostname() ?? '';
        $platformHostnames = $platform['hostnames'] ?? [];
        // Only run Router when external domain
        if (!\in_array($hostname, $platformHostnames) || !empty($previewHostname)) {
            if (router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $log, $queueForEvents, $queueForStatsUsage, $queueForFunctions, $executor, $geodb, $isResourceBlocked, $platform, $previewHostname, $authorization, $apiKey)) {
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
            if (version_compare($requestFormat, '1.8.0', '<')) {
                $dbForProject = $getProjectDB($project);
                $request->addFilter(new RequestV20($dbForProject, $route->getPathValues($request)));
            }
            if (version_compare($requestFormat, '1.9.0', '<')) {
                $request->addFilter(new RequestV21());
            }
        }

        $localeParam = (string) $request->getParam('locale', $request->getHeader('x-appwrite-locale', ''));
        if (\in_array($localeParam, $localeCodes)) {
            $locale->setDefault($localeParam);
        }

        $origin = \parse_url($request->getOrigin($request->getReferer('')), PHP_URL_HOST);
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

        $warnings = [];

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
                $warnings[] = "The current SDK is built for Appwrite " . $responseFormat . ". However, the current Appwrite server version is " . APP_VERSION_STABLE . ". Please downgrade your SDK to match the Appwrite version: https://appwrite.io/docs/sdks";
            }
        }

        // Add Appwrite warning headers
        if (!empty($warnings)) {
            $response->addHeader('X-Appwrite-Warning', implode(';', $warnings));
        }

        if (System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'enabled') { // Force HTTPS
            if ($request->getProtocol() !== 'https' && ($swooleRequest->header['host'] ?? '') !== 'localhost') { // localhost allowed for proxy, APP_HOSTNAME_INTERNAL allowed for migrations
                if ($request->getMethod() !== Request::METHOD_GET) {
                    throw new AppwriteException(AppwriteException::GENERAL_PROTOCOL_UNSUPPORTED, 'Method unsupported over HTTP. Please use HTTPS instead.');
                }

                return $response->redirect('https://' . $request->getHostname() . $request->getURI());
            }
        }
    });

/**
 * Security headers
 *
 * @see https://www.owasp.org/index.php/List_of_useful_HTTP_headers
 */
App::init()
    ->groups(['api', 'web'])
    ->inject('request')
    ->inject('response')
    ->inject('cors')
    ->inject('devKey')
    ->inject('originValidator')
    ->action(function (Request $request, Response $response, Cors $cors, Document $devKey, Validator $originValidator) {
        // CORS headers
        foreach ($cors->headers($request->getOrigin()) as $name => $value) {
            $response->addHeader($name, $value);
        }

        // Security headers
        $response
            ->addHeader('Server', 'Appwrite')
            ->addHeader('X-Content-Type-Options', 'nosniff');

        if ($request->getProtocol() === 'https') {
            $maxAge = 60 * 60 * 24 * 126; // 126 days
            $response->addHeader('Strict-Transport-Security', "max-age=$maxAge");
        }

        // Application level CSRF protection
        $origin = $request->getOrigin();
        if (empty($origin) || !$devKey->isEmpty() || !empty($request->getHeader('x-appwrite-key'))) {
            return;
        }
        $route = $request->getRoute();
        if ($route->getLabel('origin', false) === '*') {
            return;
        }
        if (!$originValidator->isValid($origin)) {
            throw new AppwriteException(AppwriteException::GENERAL_UNKNOWN_ORIGIN, $originValidator->getDescription());
        }
    });

/**
 * Automatic certificate generation
 */
App::init()
   ->groups(['api', 'web'])
   ->inject('request')
   ->inject('console')
   ->inject('dbForPlatform')
   ->inject('queueForCertificates')
   ->inject('platform')
   ->inject('authorization')
   ->action(function (Request $request, Document $console, Database $dbForPlatform, Certificate $queueForCertificates, array $platform, Authorization $authorization) {
       $hostname = $request->getHostname();
       $cache = Config::getParam('hostnames', []);
       $platformHostnames = $platform['hostnames'] ?? [];

       // 1. Cache hit
       if (array_key_exists($hostname, $cache)) {
           return;
       }

       // 2. Domain validation
       $domain = new Domain(!empty($hostname) ? $hostname : '');
       if (empty($domain->get()) || !$domain->isKnown() || $domain->isTest()) {
           $cache[$domain->get()] = false;
           Config::setParam('hostnames', $cache);
           Console::warning($domain->get() . ' is not a publicly accessible domain. Skipping SSL certificate generation.');
           return;
       }

       if (str_starts_with($request->getURI(), '/.well-known/acme-challenge')) {
           Console::warning('Skipping SSL certificates generation on ACME challenge.');
           return;
       }

       // 3. Check if domain is a main domain
       if (!in_array($domain->get(), $platformHostnames)) {
           Console::warning($domain->get() . ' is not a main domain. Skipping SSL certificate generation.');
           return;
       }

       // 4. Check/create rule (requires DB access)
       $authorization->disable();
       try {
           // TODO: (@Meldiron) Remove after 1.7.x migration
           $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
           $document = $isMd5
               ? $dbForPlatform->getDocument('rules', md5($domain->get()))
               : $dbForPlatform->findOne('rules', [
                   Query::equal('domain', [$domain->get()]),
               ]);

           if (!$document->isEmpty()) {
               return;
           }

           // 5. Create new rule
           $owner = '';
           $fallback = System::getEnv('_APP_DOMAIN_FUNCTIONS_FALLBACK', '');
           $funcDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');
           $siteDomain = System::getEnv('_APP_DOMAIN_SITES', '');

           if (!empty($fallback) && \str_ends_with($domain->get(), $fallback)) {
               $funcDomain = $fallback;
           }

           if (
               (!empty($funcDomain) && \str_ends_with($domain->get(), $funcDomain)) ||
               (!empty($siteDomain) && \str_ends_with($domain->get(), $siteDomain))
           ) {
               $owner = 'Appwrite';
           }

           $ruleId = $isMd5 ? md5($domain->get()) : ID::unique();
           $document = new Document([
               '$id' => $ruleId,
               'domain' => $domain->get(),
               'type' => 'api',
               'status' => 'verifying',
               'projectId' => $console->getId(),
               'projectInternalId' => $console->getSequence(),
               'search' => implode(' ', [$ruleId, $domain->get()]),
               'owner' => $owner,
               'region' => $console->getAttribute('region')
           ]);

           $dbForPlatform->createDocument('rules', $document);

           Console::info('Issuing a TLS certificate for the main domain (' . $domain->get() . ') in a few seconds...');
           $queueForCertificates
               ->setDomain($document)
               ->setSkipRenewCheck(true)
               ->trigger();
       } catch (Duplicate $e) {
           Console::info('Certificate already exists');
       } finally {
           $cache[$domain->get()] = true;
           Config::setParam('hostnames', $cache);
           $authorization->reset();
       }
   });

App::options()
    ->inject('utopia')
    ->inject('swooleRequest')
    ->inject('request')
    ->inject('response')
    ->inject('log')
    ->inject('dbForPlatform')
    ->inject('getProjectDB')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->inject('queueForFunctions')
    ->inject('executor')
    ->inject('geodb')
    ->inject('isResourceBlocked')
    ->inject('platform')
    ->inject('previewHostname')
    ->inject('project')
    ->inject('devKey')
    ->inject('apiKey')
    ->inject('cors')
    ->inject('authorization')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Log $log, Database $dbForPlatform, callable $getProjectDB, Event $queueForEvents, StatsUsage $queueForStatsUsage, Func $queueForFunctions, Executor $executor, Reader $geodb, callable $isResourceBlocked, array $platform, string $previewHostname, Document $project, Document $devKey, ?Key $apiKey, Cors $cors, Authorization $authorization) {
        /*
        * Appwrite Router
        */
        $platformHostnames = $platform['hostnames'] ?? [];
        // Only run Router when external domain
        if (!in_array($request->getHostname(), $platformHostnames) || !empty($previewHostname)) {
            if (router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $log, $queueForEvents, $queueForStatsUsage, $queueForFunctions, $executor, $geodb, $isResourceBlocked, $platform, $previewHostname, $apiKey)) {
                $utopia->getRoute()?->label('router', true);
            }
        }

        foreach ($cors->headers($request->getOrigin()) as $name => $value) {
            $response->addHeader($name, $value);
        }

        $response
            ->addHeader('Server', 'Appwrite')
            ->noContent();

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
    ->inject('authorization')
    ->action(function (Throwable $error, App $utopia, Request $request, Response $response, Document $project, ?Logger $logger, Log $log, StatsUsage $queueForStatsUsage, Document $devKey, Authorization $authorization) {
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
         * If not a publishable error, track usage stats. Publishable errors are >= 500 or those explicitly marked as publish=true in errors.php
         */
        if (!$publish && $project->getId() !== 'console') {
            if (!DBUser::isPrivileged($authorization->getRoles())) {
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

            $tags = $log->getTags();
            if (!isset($tags['projectId'])) {
                $log->addTag('projectId', $project->getId());
            }

            $log->addTag('hostname', $request->getHostname());
            $log->addTag('locale', (string)$request->getParam('locale', $request->getHeader('x-appwrite-locale', '')));

            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('roles', $authorization->getRoles());

            $action = 'UNKNOWN_NAMESPACE.UNKNOWN.METHOD';
            if (!empty($sdk)) {
                /** @var \Appwrite\SDK\Method $sdk */
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
            case 422: // Error allowed publicly
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
    ->inject('log')
    ->inject('dbForPlatform')
    ->inject('getProjectDB')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->inject('queueForFunctions')
    ->inject('executor')
    ->inject('geodb')
    ->inject('isResourceBlocked')
    ->inject('platform')
    ->inject('previewHostname')
    ->inject('apiKey')
    ->inject('authorization')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Log $log, Database $dbForPlatform, callable $getProjectDB, Event $queueForEvents, StatsUsage $queueForStatsUsage, Func $queueForFunctions, Executor $executor, Reader $geodb, callable $isResourceBlocked, array $platform, string $previewHostname, ?Key $apiKey, Authorization $authorization) {
        $platformHostnames = $platform['hostnames'] ?? [];
        if (in_array($request->getHostname(), $platformHostnames) || !empty($previewHostname)) {
            $template = new View(__DIR__ . '/../views/general/robots.phtml');
            $response->text($template->render(false));
        } else {
            if (router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $log, $queueForEvents, $queueForStatsUsage, $queueForFunctions, $executor, $geodb, $isResourceBlocked, $platform, $previewHostname, $authorization, $apiKey)) {
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
    ->inject('log')
    ->inject('dbForPlatform')
    ->inject('getProjectDB')
    ->inject('queueForEvents')
    ->inject('queueForStatsUsage')
    ->inject('queueForFunctions')
    ->inject('executor')
    ->inject('geodb')
    ->inject('isResourceBlocked')
    ->inject('platform')
    ->inject('previewHostname')
    ->inject('apiKey')
    ->inject('authorization')
    ->action(function (App $utopia, SwooleRequest $swooleRequest, Request $request, Response $response, Log $log, Database $dbForPlatform, callable $getProjectDB, Event $queueForEvents, StatsUsage $queueForStatsUsage, Func $queueForFunctions, Executor $executor, Reader $geodb, callable $isResourceBlocked, array $platform, string $previewHostname, ?Key $apiKey, Authorization $authorization) {
        $platformHostnames = $platform['hostnames'] ?? [];
        if (in_array($request->getHostname(), $platformHostnames) || !empty($previewHostname)) {
            $template = new View(__DIR__ . '/../views/general/humans.phtml');
            $response->text($template->render(false));
        } else {
            if (router($utopia, $dbForPlatform, $getProjectDB, $swooleRequest, $request, $response, $log, $queueForEvents, $queueForStatsUsage, $queueForFunctions, $executor, $geodb, $isResourceBlocked, $platform, $previewHostname, $authorization, $apiKey)) {
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
    ->inject('authorization')
    ->action(function (Response $response, Document $project, Database $dbForPlatform, Event $queueForEvents, Authorization $authorization) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            throw new AppwriteException(AppwriteException::PROJECT_NOT_FOUND);
        }

        $pingCount = $project->getAttribute('pingCount', 0) + 1;
        $pingedAt = DateTime::now();

        $project
            ->setAttribute('pingCount', $pingCount)
            ->setAttribute('pingedAt', $pingedAt);

        $authorization->skip(function () use ($dbForPlatform, $project) {
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
            ->addCookie(COOKIE_NAME_PREVIEW, $jwt, (new \DateTime($expire))->getTimestamp(), '/', $host, ('https' === $protocol), true, null)
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
