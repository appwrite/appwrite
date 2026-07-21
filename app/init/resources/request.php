<?php

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Auth\Key;
use Appwrite\Database\Factory as DatabaseFactory;
use Appwrite\Databases\TransactionState;
use Appwrite\Deployment\Backend;
use Appwrite\Deployment\Backend\Executor as ExecutorBackend;
use Appwrite\Deployment\Backend\Orchestrator;
use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\Functions\EventProcessor;
use Appwrite\GraphQL\Schema;
use Appwrite\Locale\GeoRecord;
use Appwrite\Locking\Lock;
use Appwrite\Network\Cors;
use Appwrite\Network\Platform;
use Appwrite\Network\Validator\Origin;
use Appwrite\Network\Validator\Redirect;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Executor\Executor;
use OpenRuntimes\Orchestrator\Jobs;
use Utopia\Agents\Adapters\Appwrite as AppwriteAdapter;
use Utopia\Agents\Agent;
use Utopia\Audit\Adapter\Database as AdapterDatabase;
use Utopia\Audit\Audit;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Code;
use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Domains\Domain;
use Utopia\Fetch\Client;
use Utopia\Http\Http;
use Utopia\Locale\Locale;
use Utopia\Lock\Distributed as DistributedLock;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Pools\Group;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

/**
 * Register per-request resources on the given container.
 * These resources depend (directly or transitively) on request/response
 * and must be fresh for each HTTP request.
 */
return function (Container $context): void {
    $context->set('utopia:graphql', fn ($utopia) => $utopia, ['utopia']);

    $context->set('log', fn () => new Log(), []);

    $context->set('logger', fn ($register) => $register->get('logger'), ['register']);

    $context->set('lock', function (Group $pools, Telemetry $telemetry, ?Logger $logger, Document $project): Lock {
        return new Lock(
            fn (string $key, int $ttl, Closure $callback): mixed => $pools->get('lock')->use(
                fn (\Redis $redis): mixed => $callback(new DistributedLock($redis, $key, $ttl))
            ),
            $telemetry,
            $logger,
            $project
        );
    }, ['pools', 'telemetry', 'logger', 'project']);

    $context->set('authorization', fn () => new Authorization(), []);

    $context->set('store', fn (): Store => new Store(), []);

    $context->set('proofForPassword', function (): Password {
        $hash = new Argon2();
        $hash
            ->setMemoryCost(7168)
            ->setTimeCost(5)
            ->setThreads(1);

        $password = new Password();
        $password
            ->setHash($hash);

        return $password;
    });

    $context->set('proofForToken', function (): Token {
        $token = new Token();
        $token->setHash(new Sha());

        return $token;
    });

    $context->set('proofForCode', function (): Code {
        $code = new Code();
        $code->setHash(new Sha());

        return $code;
    });

    $context->set('locale', function () {
        $locale = new Locale(System::getEnv('_APP_LOCALE', 'en'));
        $locale->setFallback(System::getEnv('_APP_LOCALE', 'en'));

        return $locale;
    });

    // Per-request queue resources (stateful, accumulate event data during request)
    $context->set('queueForEvents', fn (Publisher $publisher) => new Event($publisher), ['publisher']);
    $context->set('queueForWebhooks', fn (Publisher $publisher) => new Webhook($publisher), ['publisher']);
    $context->set('queueForRealtime', fn () => new Realtime(), []);
    $context->set('usage', fn () => new UsageContext(), []);
    $context->set('auditContext', fn () => new AuditContext(), []);

    $context->set('impersonatorUser', function (string $mode, Document $project, Document $user, Request $request, Database $dbForProject, Database $dbForPlatform) {
        if ($user->isEmpty() || !$user->getAttribute('impersonator', false)) {
            return new Document();
        }

        // Query params mirror the header fallback pattern used by ?project= and ?devKey=,
        // allowing Console to embed impersonation in direct file/image URLs where headers cannot be set.
        $impersonateUserId = $request->getHeaderLine('x-appwrite-impersonate-user-id', (string)($request->getParam('impersonateuserid', '') ?: $request->getParam('impersonateUserId', '')));
        $impersonateEmail = $request->getHeaderLine('x-appwrite-impersonate-user-email', (string)($request->getParam('impersonateemail', '') ?: $request->getParam('impersonateEmail', '')));
        $impersonatePhone = $request->getHeaderLine('x-appwrite-impersonate-user-phone', (string)($request->getParam('impersonatephone', '') ?: $request->getParam('impersonatePhone', '')));

        if (empty($impersonateUserId) && empty($impersonateEmail) && empty($impersonatePhone)) {
            return new Document();
        }

        $userDb = (APP_MODE_ADMIN === $mode || $project->getId() === 'console') ? $dbForPlatform : $dbForProject;
        $targetUser = null;
        if (!empty($impersonateUserId)) {
            $targetUser = $userDb->getAuthorization()->skip(fn () => $userDb->getDocument('users', $impersonateUserId));
        } elseif (!empty($impersonateEmail)) {
            $targetUser = $userDb->getAuthorization()->skip(fn () => $userDb->findOne('users', [Query::equal('email', [\strtolower($impersonateEmail)])]));
        } elseif (!empty($impersonatePhone)) {
            $targetUser = $userDb->getAuthorization()->skip(fn () => $userDb->findOne('users', [Query::equal('phone', [$impersonatePhone])]));
        }

        if ($targetUser === null || $targetUser->isEmpty()) {
            return new Document();
        }

        return new Document([
            '$id' => $user->getId(),
            '$sequence' => $user->getSequence(),
            'name' => $user->getAttribute('name', ''),
            'email' => $user->getAttribute('email', ''),
            'type' => $user->getAttribute('type', $mode === APP_MODE_ADMIN ? ACTOR_TYPE_ADMIN : ACTOR_TYPE_USER),
        ]);
    }, ['mode', 'project', 'user', 'request', 'dbForProject', 'dbForPlatform']);

    $context->set('targetUser', function (Document $user, Document $impersonatorUser, string $mode, Document $project, Request $request, Database $dbForProject, Database $dbForPlatform) {
        if ($impersonatorUser->isEmpty()) {
            return $user;
        }

        $impersonateUserId = $request->getHeaderLine('x-appwrite-impersonate-user-id', (string)($request->getParam('impersonateuserid', '') ?: $request->getParam('impersonateUserId', '')));
        $impersonateEmail = $request->getHeaderLine('x-appwrite-impersonate-user-email', (string)($request->getParam('impersonateemail', '') ?: $request->getParam('impersonateEmail', '')));
        $impersonatePhone = $request->getHeaderLine('x-appwrite-impersonate-user-phone', (string)($request->getParam('impersonatephone', '') ?: $request->getParam('impersonatePhone', '')));

        $userDb = (APP_MODE_ADMIN === $mode || $project->getId() === 'console') ? $dbForPlatform : $dbForProject;
        if (!empty($impersonateUserId)) {
            return $userDb->getAuthorization()->skip(fn () => $userDb->getDocument('users', $impersonateUserId));
        } elseif (!empty($impersonateEmail)) {
            return $userDb->getAuthorization()->skip(fn () => $userDb->findOne('users', [Query::equal('email', [\strtolower($impersonateEmail)])]));
        } elseif (!empty($impersonatePhone)) {
            return $userDb->getAuthorization()->skip(fn () => $userDb->findOne('users', [Query::equal('phone', [$impersonatePhone])]));
        }

        return $user;
    }, ['user', 'impersonatorUser', 'mode', 'project', 'request', 'dbForProject', 'dbForPlatform']);

    $context->set('publisherForFunctions', fn (Publisher $publisher) => new FunctionPublisher(
        $publisher,
        new Queue(System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', Event::FUNCTIONS_QUEUE_NAME), 'utopia-queue', Event::FUNCTIONS_QUEUE_TTL)
    ), ['publisher']);
    // Builds a Backend bound to a given project — webhook handlers resolve
    // their tenant projects mid-request, after this container is initialized.
    $context->set('deploymentsFactory', function (BuildPublisher $publisherForBuilds, Jobs $jobs, Executor $executor, array $platform) {
        return fn (Database $dbForProject, Document $project): Backend => System::getEnv('_APP_BUILDS_BACKEND', 'executor') === 'orchestrator'
            ? new Orchestrator($jobs, $dbForProject, $project, $platform)
            : new ExecutorBackend($publisherForBuilds, $dbForProject, $project, $executor, $platform);
    }, ['publisherForBuilds', 'jobs', 'executor', 'platform']);
    $context->set('deployments', fn (callable $deploymentsFactory, Database $dbForProject, Document $project) => $deploymentsFactory($dbForProject, $project), ['deploymentsFactory', 'dbForProject', 'project']);
    $context->set('eventProcessor', fn () => new EventProcessor(), []);
    $context->set('databaseFactory', fn (Group $pools, Cache $cache, Authorization $authorization) => new DatabaseFactory(
        $pools,
        $cache,
        $authorization
    ), ['pools', 'cache', 'authorization']);

    $context->set('dbForPlatform', fn (DatabaseFactory $databaseFactory) => $databaseFactory->platform(
        APP_DATABASE_TIMEOUT_MILLISECONDS_API,
        APP_DATABASE_QUERY_MAX_VALUES,
        ['host' => \gethostname(), 'project' => 'console']
    ), ['databaseFactory']);

    $context->set('getProjectDB', function (DatabaseFactory $databaseFactory, Database $dbForPlatform) {

        return function (Document $project) use ($databaseFactory, $dbForPlatform) {
            if ($project->isEmpty() || $project->getId() === 'console') {
                return $dbForPlatform;
            }

            $database = $project->getAttribute('database', '');
            if (empty($database)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Project database is not configured');
            }

            return $databaseFactory->project(
                $project,
                APP_DATABASE_TIMEOUT_MILLISECONDS_API,
                APP_DATABASE_QUERY_MAX_VALUES,
                ['host' => \gethostname(), 'project' => $project->getId()]
            );
        };
    }, ['databaseFactory', 'dbForPlatform']);

    $context->set('getLogsDB', function (DatabaseFactory $databaseFactory) {

        return function (?Document $project = null) use ($databaseFactory) {
            return $databaseFactory->logs(
                $project,
                APP_DATABASE_TIMEOUT_MILLISECONDS_API,
                APP_DATABASE_QUERY_MAX_VALUES
            );
        };
    }, ['databaseFactory']);

    /**
     * List of allowed request hostnames for the request.
     */
    $context->set('allowedHostnames', function (array $platform, Document $project, Document $rule, Document $devKey, Request $request) {
        $allowed = [...($platform['hostnames'] ?? [])];

        /* Add platform configured hostnames */
        if (! $project->isEmpty() && $project->getId() !== 'console') {
            $platforms = $project->getAttribute('platforms', []);
            $hostnames = Platform::getHostnames($platforms);
            $allowed = [...$allowed, ...$hostnames];
        }

        /* Add the request hostname if a dev key is found */
        if (! $devKey->isEmpty()) {
            $allowed[] = $request->getHostname();
        }

        $originHostname = parse_url($request->getOrigin(), PHP_URL_HOST);
        $refererHostname = parse_url($request->getReferer(), PHP_URL_HOST);

        $hostname = $originHostname;
        if (empty($hostname)) {
            $hostname = $refererHostname;
        }

        /* Add request hostname for preflight requests */
        if ($request->getMethod() === 'OPTIONS') {
            $allowed[] = $hostname;
        }

        /* Allow the request origin of rule */
        if (! $rule->isEmpty() && ! empty($rule->getAttribute('domain', ''))) {
            $allowed[] = $rule->getAttribute('domain', '');
        }

        /* Allow the request origin if a dev key is found */
        if (! $devKey->isEmpty() && ! empty($hostname)) {
            $allowed[] = $hostname;
        }

        return array_unique($allowed);
    }, ['platform', 'project', 'rule', 'devKey', 'request']);

    /**
     * List of allowed request schemes for the request.
     */
    $context->set('allowedSchemes', function (array $platform, Document $project) {
        $allowed = [...($platform['schemas'] ?? [])];

        if (! $project->isEmpty() && $project->getId() !== 'console') {
            /* Add hardcoded schemes */
            $allowed[] = 'exp';
            $allowed[] = 'appwrite-callback-' . $project->getId();

            /* Add platform configured schemes */
            $platforms = $project->getAttribute('platforms', []);
            $schemes = Platform::getSchemes($platforms);
            $allowed = [...$allowed, ...$schemes];
        }

        return array_unique($allowed);
    }, ['platform', 'project']);

    /**
     * Whether the request origin is verified against the request hostname.
     */
    $context->set('domainVerification', function (Request $request) {
        $origin = \parse_url($request->getOrigin($request->getReferer('')), PHP_URL_HOST);
        $selfDomain = new Domain($request->getHostname());
        $endDomain = new Domain((string) $origin);

        return ($selfDomain->getRegisterable() === $endDomain->getRegisterable())
            && $endDomain->getRegisterable() !== '';
    }, ['request']);

    /**
     * Cookie domain for the current request.
     */
    $context->set('cookieDomain', function (Request $request, Document $project) {
        $localHosts = ['localhost', 'localhost:' . $request->getPort()];

        $migrationHost = System::getEnv('_APP_MIGRATION_HOST');
        if (!empty($migrationHost)) {
            // Treat the migration host like localhost because internal migration and CI
            // traffic may use it before a public domain is configured.
            $localHosts[] = $migrationHost;
            $localHosts[] = $migrationHost . ':' . $request->getPort();
        }

        $hostname = $request->getHostname();
        $isLocalHost = \in_array($hostname, $localHosts, true);
        $isIpAddress = \filter_var($hostname, FILTER_VALIDATE_IP) !== false;

        if ($isLocalHost || $isIpAddress) {
            return;
        }

        $isConsoleProject = $project->getAttribute('$id', '') === 'console';
        $isConsoleRootSession = System::getEnv('_APP_CONSOLE_ROOT_SESSION', 'disabled') === 'enabled';

        if ($isConsoleProject && $isConsoleRootSession) {
            $domain = new Domain($hostname);

            return '.' . $domain->getRegisterable();
        }

        return '.' . $hostname;
    }, ['request', 'project']);

    /**
     * Rule associated with a request origin.
     */
    $context->set('rule', function (Request $request, Database $dbForPlatform, Document $project, Authorization $authorization) {
        $domain = \parse_url($request->getOrigin(), PHP_URL_HOST);

        if (empty($domain)) {
            $domain = \parse_url($request->getReferer(), PHP_URL_HOST);
        }

        if (empty($domain)) {
            return new Document();
        }

        // TODO: (@Meldiron) Remove after 1.7.x migration
        $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
        $rule = $authorization->skip(function () use ($dbForPlatform, $domain, $isMd5) {
            if ($isMd5) {
                return $dbForPlatform->getDocument('rules', md5($domain));
            }

            return $dbForPlatform->findOne('rules', [
                Query::equal('domain', [$domain]),
            ]);
        });

        $permitsCurrentProject = $rule->getAttribute('projectInternalId', '') === $project->getSequence();

        // Temporary implementation until custom wildcard domains are an official feature
        // Allow trusted projects; Used for Console (website) previews
        if (! $permitsCurrentProject && ! $rule->isEmpty() && ! empty($rule->getAttribute('projectId', ''))) {
            $trustedProjects = [];
            foreach (\explode(',', System::getEnv('_APP_CONSOLE_TRUSTED_PROJECTS', '')) as $trustedProject) {
                if (empty($trustedProject)) {
                    continue;
                }
                $trustedProjects[] = $trustedProject;
            }
            if (\in_array($rule->getAttribute('projectId', ''), $trustedProjects)) {
                $permitsCurrentProject = true;
            }
        }

        if (! $permitsCurrentProject) {
            return new Document();
        }

        return $rule;
    }, ['request', 'dbForPlatform', 'project', 'authorization']);

    /**
     * CORS service
     */
    $context->set('cors', function (array $allowedHostnames) {
        $corsConfig = Config::getParam('cors');

        return new Cors(
            $allowedHostnames,
            allowedMethods: $corsConfig['allowedMethods'],
            allowedHeaders: $corsConfig['allowedHeaders'],
            allowCredentials: true,
            exposedHeaders: $corsConfig['exposedHeaders'],
        );
    }, ['allowedHostnames']);

    $context->set(
        'originValidator',
        fn (Document $devKey, array $allowedHostnames, array $allowedSchemes) => $devKey->isEmpty()
            ? new Origin($allowedHostnames, $allowedSchemes)
            : new URL(),
        ['devKey', 'allowedHostnames', 'allowedSchemes']
    );

    $context->set(
        'redirectValidator',
        fn (Document $devKey, array $allowedHostnames, array $allowedSchemes) => $devKey->isEmpty()
            ? new Redirect($allowedHostnames, $allowedSchemes)
            : new URL(),
        ['devKey', 'allowedHostnames', 'allowedSchemes']
    );

    $context->set('user', function (string $mode, Document $project, Document $console, Request $request, Response $response, Database $dbForProject, Database $dbForPlatform, Store $store, Token $proofForToken, $authorization) {
        /**
         * Handles user authentication and session validation.
         *
         * This function follows a series of steps to determine the appropriate user session
         * based on cookies, headers, and JWT tokens.
         *
         * Process:
         * 1. Checks the cookie based on mode:
         *    - If in admin mode, uses console project id for key.
         *    - Otherwise, sets the key using the project ID
         * 2. If no cookie is found, attempts to retrieve the fallback header `x-fallback-cookies`.
         *    - If this method is used, returns the header: `X-Debug-Fallback: true`.
         * 3. Fetches the user document from the appropriate database based on the mode.
         * 4. If the user document is empty or the session key cannot be verified, sets an empty user document.
         * 5. Regardless of the results from steps 1-4, attempts to fetch the JWT token.
         * 6. If the JWT user has a valid session ID, updates the user variable with the user from `projectDB`,
         *    overwriting the previous value.
         * 7. If account API key is passed, use user of the account API key as long as user ID header matches too
         */
        $authorization->setDefaultStatus(true);

        $store->setKey('a_session_' . $project->getId());

        if ($mode === APP_MODE_ADMIN) {
            $store->setKey('a_session_' . $console->getId());
        }

        $store->decode(
            $request->getCookie(
                $store->getKey(), // Get sessions
                $request->getCookie($store->getKey() . '_legacy', '')
            )
        );

        // Get session from header for SSR clients
        if (empty($store->getProperty('id', '')) && empty($store->getProperty('secret', ''))) {
            $sessionHeader = $request->getHeaderLine('x-appwrite-session', '');

            if (! empty($sessionHeader)) {
                $store->decode($sessionHeader);
            }
        }

        // Get fallback session from old clients (no SameSite support) or clients who block 3rd-party cookies
        $response->addHeader('X-Debug-Fallback', 'false');

        if (empty($store->getProperty('id', '')) && empty($store->getProperty('secret', ''))) {
            $response->addHeader('X-Debug-Fallback', 'true');
            $fallback = $request->getHeaderLine('x-fallback-cookies', '');
            $fallback = \json_decode($fallback, true);
            $store->decode(((is_array($fallback) && isset($fallback[$store->getKey()])) ? $fallback[$store->getKey()] : ''));
        }

        $user = null;
        if ($mode === APP_MODE_ADMIN) {
            /** @var User $user */
            $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
        } else {
            if ($project->isEmpty()) {
                $user = new User([]);
            } else {
                if (! empty($store->getProperty('id', ''))) {
                    if ($project->getId() === 'console') {
                        /** @var User $user */
                        $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
                    } else {
                        /** @var User $user */
                        $user = $dbForProject->getDocument('users', $store->getProperty('id', ''));
                    }
                }
            }
        }

        if (
            ! $user ||
            $user->isEmpty() // Check a document has been found in the DB
            || ! $user->sessionVerify($store->getProperty('secret', ''), $proofForToken)
        ) { // Validate user has valid login token
            $user = new User([]);
        }

        $authJWT = $request->getHeaderLine('x-appwrite-jwt', '');
        if (! empty($authJWT) && ! $project->isEmpty()) { // JWT authentication
            if (! $user->isEmpty()) {
                throw new Exception(Exception::USER_JWT_AND_COOKIE_SET);
            }

            $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 3600, 0);
            try {
                $payload = $jwt->decode($authJWT);
            } catch (JWTException $error) {
                throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
            }

            $jwtUserId = $payload['userId'] ?? '';
            if (! empty($jwtUserId)) {
                if ($mode === APP_MODE_ADMIN) {
                    $user = $dbForPlatform->getDocument('users', $jwtUserId);
                } else {
                    $user = $dbForProject->getDocument('users', $jwtUserId);
                }
            }
            $jwtSessionId = $payload['sessionId'] ?? '';
            if (! empty($jwtSessionId)) {
                if (empty($user->find('$id', $jwtSessionId, 'sessions'))) { // Match JWT to active token
                    $user = new User([]);
                }
            }
        }

        // Account based on account API key
        $accountKey = $request->getHeaderLine('x-appwrite-key', '');
        $accountKeyUserId = $request->getHeaderLine('x-appwrite-user', '');
        if (! empty($accountKeyUserId) && ! empty($accountKey)) {
            if (! $user->isEmpty()) {
                throw new Exception(Exception::USER_API_KEY_AND_SESSION_SET);
            }

            $accountKeyUser = $dbForPlatform->getAuthorization()->skip(fn () => $dbForPlatform->getDocument('users', $accountKeyUserId));
            if (! $accountKeyUser->isEmpty()) {
                $key = $accountKeyUser->find(
                    key: 'secret',
                    find: $accountKey,
                    subject: 'keys'
                );

                if (! empty($key)) {
                    $expire = $key->getAttribute('expire');
                    if (! empty($expire) && $expire < DatabaseDateTime::formatTz(DatabaseDateTime::now())) {
                        throw new Exception(Exception::ACCOUNT_KEY_EXPIRED);
                    }

                    $user = $accountKeyUser;
                }
            }
        }

        $dbForProject->setMetadata('user', $user->getId());
        $dbForPlatform->setMetadata('user', $user->getId());

        return $user;
    }, ['mode', 'project', 'console', 'request', 'response', 'dbForProject', 'dbForPlatform', 'store', 'proofForToken', 'authorization']);

    $context->set('project', function ($dbForPlatform, $request, $console, $authorization, Http $utopia) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Utopia\Database\Database $dbForPlatform */
        /** @var Utopia\Database\Document $console */
        $projectId = $request->getParam('project', $request->getHeaderLine('x-appwrite-project', ''));
        // Realtime channel "project" can send project=Query array
        if (! \is_string($projectId)) {
            $projectId = $request->getHeaderLine('x-appwrite-project', '');
        }
        // For non-GET requests getParam() reads the body, so a project passed
        // as a query parameter (e.g. presigned artifact URLs) is only visible
        // via getQuery().
        if (empty($projectId)) {
            $projectId = (string) $request->getQuery('project', '');
        }

        // Backwards compatibility for new services, originally project resources
        // These endpoints moved from /v1/projects/:projectId/<resource> to /v1/<resource>
        // When accessed via the old alias path, extract projectId from the URI
        $deprecatedProjectPathPrefix = '/v1/projects/';
        $route = $utopia->match($request)?->route;
        if (!empty($route)) {
            $isDeprecatedAlias = \str_starts_with($request->getURI(), $deprecatedProjectPathPrefix) &&
                !\str_starts_with($route->getPath(), $deprecatedProjectPathPrefix);

            if ($isDeprecatedAlias) {
                $projectId = \explode('/', $request->getURI(), 5)[3] ?? '';
            }
        }

        if (empty($projectId) || $projectId === 'console') {
            return $console;
        }

        $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));

        return $project;
    }, ['dbForPlatform', 'request', 'console', 'authorization', 'utopia']);

    $context->set('session', function (User $user, Store $store, Token $proofForToken) {
        if ($user->isEmpty()) {
            return;
        }

        $sessions = $user->getAttribute('sessions', []);
        $sessionId = $user->sessionVerify($store->getProperty('secret', ''), $proofForToken);

        if (! $sessionId) {
            return;
        }
        foreach ($sessions as $session) {
            /** @var Document $session */
            if ($sessionId === $session->getId()) {
                return $session;
            }
        }

        return;
    }, ['user', 'store', 'proofForToken']);

    $context->set('dbForProject', function (DatabaseFactory $databaseFactory, Database $dbForPlatform, Document $project, Response $response, Publisher $publisher, Publisher $publisherFunctions, Publisher $publisherWebhooks, Event $queueForEvents, FunctionPublisher $publisherForFunctions, Webhook $queueForWebhooks, Realtime $queueForRealtime, UsageContext $usage, Request $request) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForPlatform;
        }

        $database = $project->getAttribute('database', '');
        if (empty($database)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Project database is not configured');
        }

        $database = $databaseFactory->project(
            $project,
            APP_DATABASE_TIMEOUT_MILLISECONDS_API,
            APP_DATABASE_QUERY_MAX_VALUES,
            ['host' => \gethostname(), 'project' => $project->getId()]
        );

        /**
         * This isolated event handling for `users.*.create` which is based on a `Database::EVENT_DOCUMENT_CREATE` listener may look odd, but it is **intentional**.
         *
         * Accounts can be created in many ways beyond `createAccount`
         * (anonymous, OAuth, phone, etc.), and those flows are probably not covered in event tests; so we handle this here.
         */
        $eventDatabaseListener = function (Document $project, Document $document, Response $response, Event $queueForEvents, FunctionPublisher $publisherForFunctions, Webhook $queueForWebhooks, Realtime $queueForRealtime) {
            // Only trigger events for user creation with the database listener.
            if ($document->getCollection() !== 'users') {
                return;
            }

            $queueForEvents
                ->setEvent('users.[userId].create')
                ->setParam('userId', $document->getId())
                ->setPayload($response->output($document, Response::MODEL_USER));

            // Trigger functions, webhooks, and realtime events
            $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
                event: $queueForEvents->getEvent(),
                params: $queueForEvents->getParams(),
                project: $queueForEvents->getProject(),
                user: $queueForEvents->getUser(),
                userId: $queueForEvents->getUserId(),
                payload: $queueForEvents->getPayload(),
                platform: $queueForEvents->getPlatform(),
            ));

            /** Trigger webhooks events only if a project has them enabled */
            if (! empty($project->getAttribute('webhooks'))) {
                $queueForWebhooks
                    ->from($queueForEvents)
                    ->trigger();
            }

            /** Trigger realtime events only for non console events */
            if ($queueForEvents->getProject()->getId() !== 'console') {
                $queueForRealtime
                    ->from($queueForEvents)
                    ->trigger();
            }
        };

        /**
         * Purge function events cache when functions are created, updated or deleted.
         */
        $functionsEventsCacheListener = function (string $event, Document $document, Document $project, Database $dbForProject) {

            if ($document->getCollection() !== 'functions') {
                return;
            }

            if ($project->isEmpty() || $project->getId() === 'console') {
                return;
            }

            $hostname = $dbForProject->getAdapter()->getHostname();
            $cacheKey = \sprintf(
                '%s-cache-%s:%s:%s:project:%s:functions:events',
                $dbForProject->getCacheName(),
                $hostname,
                $dbForProject->getNamespace(),
                $dbForProject->getTenant(),
                $project->getId()
            );

            $dbForProject->getCache()->purge($cacheKey);
        };

        /**
         * Prefix metrics with database type when applicable.
         * Avoids prefixing for legacy and tablesdb types to preserve historical metrics.
         */
        $getDatabaseTypePrefixedMetric = function (string $databaseType, string $metric): string {
            if (
                $databaseType === '' ||
                $databaseType === DATABASE_TYPE_LEGACY ||
                $databaseType === DATABASE_TYPE_TABLESDB
            ) {
                return $metric;
            }

            return $databaseType . '.' . $metric;
        };

        // Determine database type from request path, similar to api.php
        $path = $request->getURI();
        $databaseType = match (true) {
            str_contains($path, '/documentsdb') => DATABASE_TYPE_DOCUMENTSDB,
            str_contains($path, '/vectorsdb') => DATABASE_TYPE_VECTORSDB,
            default => '',
        };

        $usageDatabaseListener = function (string $event, Document $document, UsageContext $usage) use ($getDatabaseTypePrefixedMetric, $databaseType) {
            $value = 1;

            switch ($event) {
                case Database::EVENT_DOCUMENT_DELETE:
                    $value = -1;
                    break;
                case Database::EVENT_DOCUMENTS_DELETE:
                    $value = -1 * $document->getAttribute('modified', 0);
                    break;
                case Database::EVENT_DOCUMENTS_CREATE:
                    $value = $document->getAttribute('modified', 0);
                    break;
                case Database::EVENT_DOCUMENTS_UPSERT:
                    $value = $document->getAttribute('created', 0);
                    break;
            }

            switch (true) {
                case $document->getCollection() === 'teams':
                    $usage->addMetric(METRIC_TEAMS, $value); // per project
                    break;
                case $document->getCollection() === 'users':
                    $usage->addMetric(METRIC_USERS, $value); // per project
                    if ($event === Database::EVENT_DOCUMENT_DELETE) {
                        $usage->addReduce($document);
                    }
                    break;
                case $document->getCollection() === 'sessions': // sessions
                    $usage->addMetric(METRIC_SESSIONS, $value); // per project
                    break;
                case $document->getCollection() === 'databases': // databases
                    $metric = $getDatabaseTypePrefixedMetric($databaseType, METRIC_DATABASES);
                    $usage->addMetric($metric, $value); // per project

                    if ($event === Database::EVENT_DOCUMENT_DELETE) {
                        $usage->addReduce($document);
                    }
                    break;
                case str_starts_with($document->getCollection(), 'database_') && ! str_contains($document->getCollection(), 'collection'): // collections
                    $parts = explode('_', $document->getCollection());
                    $databaseInternalId = $parts[1] ?? 0;
                    $collectionMetric = $getDatabaseTypePrefixedMetric($databaseType, METRIC_COLLECTIONS);
                    $databaseIdCollectionMetric = $getDatabaseTypePrefixedMetric($databaseType, METRIC_DATABASE_ID_COLLECTIONS);
                    $usage
                        ->addMetric($collectionMetric, $value) // per project
                        ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, $databaseIdCollectionMetric), $value);

                    if ($event === Database::EVENT_DOCUMENT_DELETE) {
                        $usage->addReduce($document);
                    }
                    break;
                case str_starts_with($document->getCollection(), 'database_') && str_contains($document->getCollection(), '_collection_'): // documents
                    $parts = explode('_', $document->getCollection());
                    $databaseInternalId = $parts[1] ?? 0;
                    $collectionInternalId = $parts[3] ?? 0;
                    $documentsMetric = $getDatabaseTypePrefixedMetric($databaseType, METRIC_DOCUMENTS);
                    $databaseIdDocumentsMetric = $getDatabaseTypePrefixedMetric($databaseType, METRIC_DATABASE_ID_DOCUMENTS);
                    $databaseIdCollectionIdDocumentsMetric = $getDatabaseTypePrefixedMetric($databaseType, METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS);
                    $usage
                        ->addMetric($documentsMetric, $value)  // per project
                        ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, $databaseIdDocumentsMetric), $value) // per database
                        ->addMetric(str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$databaseInternalId, $collectionInternalId], $databaseIdCollectionIdDocumentsMetric), $value);  // per collection
                    break;
                case $document->getCollection() === 'buckets': // buckets
                    $usage->addMetric(METRIC_BUCKETS, $value); // per project
                    if ($event === Database::EVENT_DOCUMENT_DELETE) {
                        $usage
                            ->addReduce($document);
                    }
                    break;
                case str_starts_with($document->getCollection(), 'bucket_'): // files
                    $parts = explode('_', $document->getCollection());
                    $bucketInternalId = $parts[1];
                    $usage
                        ->addMetric(METRIC_FILES, $value) // per project
                        ->addMetric(METRIC_FILES_STORAGE, $document->getAttribute('sizeOriginal') * $value) // per project
                        ->addMetric(str_replace('{bucketInternalId}', $bucketInternalId, METRIC_BUCKET_ID_FILES), $value) // per bucket
                        ->addMetric(str_replace('{bucketInternalId}', $bucketInternalId, METRIC_BUCKET_ID_FILES_STORAGE), $document->getAttribute('sizeOriginal') * $value); // per bucket
                    break;
                case $document->getCollection() === 'functions':
                    $usage->addMetric(METRIC_FUNCTIONS, $value); // per project

                    if ($event === Database::EVENT_DOCUMENT_DELETE) {
                        $usage
                            ->addReduce($document);
                    }
                    break;
                case $document->getCollection() === 'sites':
                    $usage->addMetric(METRIC_SITES, $value); // per project

                    if ($event === Database::EVENT_DOCUMENT_DELETE) {
                        $usage
                            ->addReduce($document);
                    }
                    break;
                case $document->getCollection() === 'deployments':
                    $usage
                        ->addMetric(METRIC_DEPLOYMENTS, $value) // per project
                        ->addMetric(METRIC_DEPLOYMENTS_STORAGE, $document->getAttribute('size') * $value) // per project
                        ->addMetric(str_replace(['{resourceType}'], [$document->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_DEPLOYMENTS), $value) // per function
                        ->addMetric(str_replace(['{resourceType}'], [$document->getAttribute('resourceType')], METRIC_RESOURCE_TYPE_DEPLOYMENTS_STORAGE), $document->getAttribute('size') * $value)
                        ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$document->getAttribute('resourceType'), $document->getAttribute('resourceInternalId')], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS), $value) // per function
                        ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$document->getAttribute('resourceType'), $document->getAttribute('resourceInternalId')], METRIC_RESOURCE_TYPE_ID_DEPLOYMENTS_STORAGE), $document->getAttribute('size') * $value);
                    break;
                default:
                    break;
            }
        };

        // Clone the queues, to prevent events triggered by the database listener
        // from overwriting the events that are supposed to be triggered in the shutdown hook.
        $queueForEventsClone = new Event($publisher);
        $queueForWebhooks = new Webhook($publisherWebhooks);
        $queueForRealtime = new Realtime();

        $database
            ->on(Database::EVENT_DOCUMENT_CREATE, 'calculate-usage', fn ($event, $document) => $usageDatabaseListener($event, $document, $usage))
            ->on(Database::EVENT_DOCUMENT_DELETE, 'calculate-usage', fn ($event, $document) => $usageDatabaseListener($event, $document, $usage))
            ->on(Database::EVENT_DOCUMENTS_CREATE, 'calculate-usage', fn ($event, $document) => $usageDatabaseListener($event, $document, $usage))
            ->on(Database::EVENT_DOCUMENTS_DELETE, 'calculate-usage', fn ($event, $document) => $usageDatabaseListener($event, $document, $usage))
            ->on(Database::EVENT_DOCUMENTS_UPSERT, 'calculate-usage', fn ($event, $document) => $usageDatabaseListener($event, $document, $usage))
            ->on(Database::EVENT_DOCUMENT_CREATE, 'create-trigger-events', fn ($event, $document) => $eventDatabaseListener(
                $project,
                $document,
                $response,
                $queueForEventsClone->from($queueForEvents),
                $publisherForFunctions,
                $queueForWebhooks->from($queueForEvents),
                $queueForRealtime->from($queueForEvents)
            ))
            ->on(Database::EVENT_DOCUMENT_CREATE, 'purge-function-events-cache', fn ($event, $document) => $functionsEventsCacheListener($event, $document, $project, $database))
            ->on(Database::EVENT_DOCUMENT_UPDATE, 'purge-function-events-cache', fn ($event, $document) => $functionsEventsCacheListener($event, $document, $project, $database))
            ->on(Database::EVENT_DOCUMENT_DELETE, 'purge-function-events-cache', fn ($event, $document) => $functionsEventsCacheListener($event, $document, $project, $database));

        return $database;
    }, ['databaseFactory', 'dbForPlatform', 'project', 'response', 'publisher', 'publisherFunctions', 'publisherWebhooks', 'queueForEvents', 'publisherForFunctions', 'queueForWebhooks', 'queueForRealtime', 'usage', 'request']);

    $context->set('schema', function ($utopia, $dbForProject, $authorization) {

        $complexity = function (int $complexity, array $args) {
            $queries = Query::parseQueries($args['queries'] ?? []);
            $query = Query::getByType($queries, [Query::TYPE_LIMIT])[0] ?? null;
            $limit = $query ? $query->getValue() : APP_LIMIT_LIST_DEFAULT;

            return $complexity * $limit;
        };

        $attributes = function (int $limit, int $offset) use ($dbForProject, $authorization) {
            $attrs = $authorization->skip(fn () => $dbForProject->find('attributes', [
                Query::limit($limit),
                Query::offset($offset),
            ]));

            return \array_map(function ($attr) {
                return $attr->getArrayCopy();
            }, $attrs);
        };

        $urls = [
            'list' => function (string $databaseId, string $collectionId, array $args) {
                return "/v1/databases/$databaseId/collections/$collectionId/documents";
            },
            'create' => function (string $databaseId, string $collectionId, array $args) {
                return "/v1/databases/$databaseId/collections/$collectionId/documents";
            },
            'read' => function (string $databaseId, string $collectionId, array $args) {
                return "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['documentId']}";
            },
            'update' => function (string $databaseId, string $collectionId, array $args) {
                return "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['documentId']}";
            },
            'delete' => function (string $databaseId, string $collectionId, array $args) {
                return "/v1/databases/$databaseId/collections/$collectionId/documents/{$args['documentId']}";
            },
        ];

        // NOTE: `params` and `urls` are not used internally in the `Schema::build` function below!
        $params = [
            'list' => function (string $databaseId, string $collectionId, array $args) {
                return ['queries' => $args['queries']];
            },
            'create' => function (string $databaseId, string $collectionId, array $args) {
                $id = $args['id'] ?? 'unique()';
                $permissions = $args['permissions'] ?? null;

                unset($args['id']);
                unset($args['permissions']);

                // Order must be the same as the route params
                return [
                    'databaseId' => $databaseId,
                    'documentId' => $id,
                    'collectionId' => $collectionId,
                    'data' => $args,
                    'permissions' => $permissions,
                ];
            },
            'update' => function (string $databaseId, string $collectionId, array $args) {
                $documentId = $args['id'];
                $permissions = $args['permissions'] ?? null;

                unset($args['id']);
                unset($args['permissions']);

                // Order must be the same as the route params
                return [
                    'databaseId' => $databaseId,
                    'collectionId' => $collectionId,
                    'documentId' => $documentId,
                    'data' => $args,
                    'permissions' => $permissions,
                ];
            },
        ];

        return Schema::build(
            $utopia,
            $complexity,
            $attributes,
            $urls,
            $params,
        );
    }, ['utopia', 'dbForProject', 'authorization']);

    $context->set('audit', fn ($dbForProject) => new Audit(new AdapterDatabase($dbForProject)), ['dbForProject']);

    $context->set('mode', function ($request, Document $project) {
        /** @var Appwrite\Utopia\Request $request */

        /**
         * Defines the mode for the request:
         * - 'default' => Requests for Client and Server Side
         * - 'admin' => Request from the Console on non-console projects
         */
        $mode = $request->getParam('mode', $request->getHeaderLine('x-appwrite-mode', APP_MODE_DEFAULT));

        $projectId = $request->getParam('project', $request->getHeaderLine('x-appwrite-project', ''));
        if (!empty($projectId) && $project->getId() !== $projectId) {
            $mode = APP_MODE_ADMIN;
        }

        return $mode;
    }, ['request', 'project']);

    $context->set('requestTimestamp', function ($request) {
        // TODO: Move this to the Request class itself
        $timestampHeader = $request->getHeaderLine('x-appwrite-timestamp');
        $requestTimestamp = null;
        if (! empty($timestampHeader)) {
            try {
                $requestTimestamp = new \DateTime($timestampHeader);
            } catch (\Throwable $e) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid X-Appwrite-Timestamp header value');
            }
        }

        return $requestTimestamp;
    }, ['request']);

    $context->set('devKey', function (Request $request, Document $project, array $servers, Database $dbForPlatform, Authorization $authorization) {
        $devKey = $request->getHeaderLine('x-appwrite-dev-key', $request->getParam('devKey', ''));

        // Check if given key match project's development keys
        $key = $project->find('secret', $devKey, 'devKeys');
        if (! $key) {
            return new Document([]);
        }

        // check expiration
        $expire = $key->getAttribute('expire');
        if (! empty($expire) && $expire < DatabaseDateTime::formatTz(DatabaseDateTime::now())) {
            return new Document([]);
        }

        // update access time
        $accessedAt = $key->getAttribute('accessedAt', 0);
        if (empty($accessedAt) || DatabaseDateTime::formatTz(DatabaseDateTime::addSeconds(new \DateTime(), -APP_KEY_ACCESS)) > $accessedAt) {
            $key->setAttribute('accessedAt', DatabaseDateTime::now());
            $authorization->skip(fn () => $dbForPlatform->updateDocument('devKeys', $key->getId(), new Document([
                'accessedAt' => $key->getAttribute('accessedAt')
            ])));
            $dbForPlatform->purgeCachedDocument('projects', $project->getId());
        }

        // add sdk to key
        $sdkValidator = new WhiteList($servers, true);
        $sdk = \strtolower($request->getHeaderLine('x-sdk-name', 'UNKNOWN'));

        if ($sdk !== 'unknown' && $sdkValidator->isValid($sdk)) {
            $sdks = $key->getAttribute('sdks', []);

            if (! in_array($sdk, $sdks)) {
                $sdks[] = $sdk;
                $key->setAttribute('sdks', $sdks);

                /** Update access time as well */
                $key->setAttribute('accessedAt', DatabaseDateTime::now());
                $key = $authorization->skip(fn () => $dbForPlatform->updateDocument('devKeys', $key->getId(), new Document([
                    'sdks' => $key->getAttribute('sdks'),
                    'accessedAt' => $key->getAttribute('accessedAt')
                ])));
                $dbForPlatform->purgeCachedDocument('projects', $project->getId());
            }
        }

        return $key;
    }, ['request', 'project', 'servers', 'dbForPlatform', 'authorization']);

    $context->set('team', function (Document $project, Database $dbForPlatform, Http $utopia, Request $request, Authorization $authorization) {
        $teamInternalId = '';
        if ($project->getId() !== 'console') {
            $teamInternalId = $project->getAttribute('teamInternalId', '');
        } else {
            $route = $utopia->match($request)?->route;
            $path = ! empty($route) ? $route->getPath() : $request->getURI();
            $orgHeader = $request->getHeaderLine('x-appwrite-organization', '');
            if (str_starts_with($path, '/v1/projects/:projectId')) {
                $uri = $request->getURI();
                $pid = explode('/', $uri)[3];
                $p = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $pid));
                $teamInternalId = $p->getAttribute('teamInternalId', '');
            } elseif ($path === '/v1/projects') {
                $teamId = $request->getParam('teamId', '');

                if (empty($teamId)) {
                    return new Document([]);
                }

                $team = $authorization->skip(fn () => $dbForPlatform->getDocument('teams', $teamId));

                return $team;
            } elseif (! empty($orgHeader)) {
                return $authorization->skip(fn () => $dbForPlatform->getDocument('teams', $orgHeader));
            }
        }

        // if teamInternalId is empty, return an empty document

        if (empty($teamInternalId)) {
            return new Document([]);
        }

        $team = $authorization->skip(function () use ($dbForPlatform, $teamInternalId) {
            return $dbForPlatform->findOne('teams', [
                Query::equal('$sequence', [$teamInternalId]),
            ]);
        });

        return $team;
    }, ['project', 'dbForPlatform', 'utopia', 'request', 'authorization']);

    $context->set('previewHostname', function (Request $request, ?Key $apiKey) {
        $allowed = false;

        if (Http::isDevelopment()) {
            $allowed = true;
        } elseif (! \is_null($apiKey) && $apiKey->getHostnameOverride() === true) {
            $allowed = true;
        }

        if ($allowed) {
            $host = $request->getQuery('appwrite-hostname', $request->getHeaderLine('x-appwrite-hostname', '')) ?? '';
            if (! empty($host)) {
                return $host;
            }
        }

        return '';
    }, ['request', 'apiKey']);

    $context->set('apiKey', function (Request $request, Document $project, Document $team, Document $user): ?Key {
        $key = $request->getHeaderLine('x-appwrite-key');

        if (empty($key)) {
            return null;
        }

        $key = Key::decode($project, $team, $user, $key);

        $userHeader = $request->getHeaderLine('x-appwrite-user');
        $organizationHeader = $request->getHeaderLine('x-appwrite-organization');
        $projectHeader = $request->getHeaderLine('x-appwrite-project');

        if (! empty($key->getProjectId())) {
            if (empty($projectHeader) || $projectHeader !== $key->getProjectId()) {
                throw new Exception(Exception::PROJECT_ID_MISSING);
            }
        }

        if (! empty($key->getUserId())) {
            if (empty($userHeader) || $userHeader !== $key->getUserId()) {
                throw new Exception(Exception::USER_ID_MISSING);
            }
        }

        if (! empty($key->getTeamId())) {
            if (empty($organizationHeader) || $organizationHeader !== $key->getTeamId()) {
                throw new Exception(Exception::ORGANIZATION_ID_MISSING);
            }
        }

        return $key;
    }, ['request', 'project', 'team', 'user']);

    $context->set('resourceToken', function ($project, $dbForProject, $request, Authorization $authorization) {
        $tokenJWT = $request->getParam('token');

        if (! empty($tokenJWT) && ! $project->isEmpty()) { // JWT authentication
            // Use a large but reasonable maxAge to avoid auto-exp when token has no expiry
            $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), RESOURCE_TOKEN_ALGORITHM, RESOURCE_TOKEN_MAX_AGE, RESOURCE_TOKEN_LEEWAY); // Instantiate with key, algo, maxAge and leeway.

            try {
                $payload = $jwt->decode($tokenJWT);
            } catch (JWTException $error) {
                return new Document([]);
            }

            $tokenId = $payload['tokenId'] ?? '';
            if (empty($tokenId)) {
                return new Document([]);
            }

            try {
                $token = $authorization->skip(fn () => $dbForProject->getDocument('resourceTokens', $tokenId));
            } catch (\Utopia\Database\Exception\NotFound) {
                return new Document([]);
            }

            if ($token->isEmpty()) {
                return new Document([]);
            }

            $expiry = $token->getAttribute('expire');

            if ($expiry !== null) {
                $now = new \DateTime();
                $expiryDate = new \DateTime($expiry);

                if ($expiryDate < $now) {
                    return new Document([]);
                }
            }

            return match ($token->getAttribute('resourceType')) {
                TOKENS_RESOURCE_TYPE_FILES => (function () use ($token, $dbForProject, $authorization) {
                    $sequences = explode(':', $token->getAttribute('resourceInternalId'));
                    $ids = explode(':', $token->getAttribute('resourceId'));

                    if (count($sequences) !== 2 || count($ids) !== 2) {
                        return new Document([]);
                    }

                    $accessedAt = $token->getAttribute('accessedAt', 0);
                    if (empty($accessedAt) || DatabaseDateTime::formatTz(DatabaseDateTime::addSeconds(new \DateTime(), -APP_RESOURCE_TOKEN_ACCESS)) > $accessedAt) {
                        $token->setAttribute('accessedAt', DatabaseDateTime::now());
                        $authorization->skip(fn () => $dbForProject->updateDocument('resourceTokens', $token->getId(), new Document([
                            'accessedAt' => $token->getAttribute('accessedAt')
                        ])));
                    }

                    return new Document([
                        'bucketId' => $ids[0],
                        'fileId' => $ids[1],
                        'bucketInternalId' => $sequences[0],
                        'fileInternalId' => $sequences[1],
                    ]);
                })(),

                default => throw new Exception(Exception::TOKEN_RESOURCE_TYPE_INVALID),
            };
        }

        return new Document([]);
    }, ['project', 'dbForProject', 'request', 'authorization']);

    $context->set('getDatabasesDB', function (DatabaseFactory $databaseFactory, Document $project, Request $request, UsageContext $usage) {

        return function (Document $database) use ($databaseFactory, $project, $request, $usage): Database {
            $databaseType = $database->getAttribute('type', '');

            $database = $databaseFactory->tenant(
                $database,
                $project,
                APP_DATABASE_TIMEOUT_MILLISECONDS_API,
                APP_DATABASE_QUERY_MAX_VALUES,
                ['host' => \gethostname(), 'project' => $project->getId()]
            );

            $timeout = \intval($request->getHeaderLine('x-appwrite-timeout'));
            if (!empty($timeout) && Http::isDevelopment()) {
                $database->setTimeout($timeout);
            }

            // Register database event listeners for usage stats collection
            $documentsMetric = METRIC_DOCUMENTS;
            $databaseIdDocumentsMetric = METRIC_DATABASE_ID_DOCUMENTS;
            $databaseIdCollectionIdDocumentsMetric = METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS;
            if ($databaseType !== DATABASE_TYPE_LEGACY && $databaseType !== DATABASE_TYPE_TABLESDB) {
                $documentsMetric = $databaseType . '.' . $documentsMetric;
                $databaseIdDocumentsMetric = $databaseType . '.' . $databaseIdDocumentsMetric;
                $databaseIdCollectionIdDocumentsMetric = $databaseType . '.' . $databaseIdCollectionIdDocumentsMetric;
            }
            $database
                ->on(Database::EVENT_DOCUMENT_CREATE, 'calculate-usage', function ($event, $document) use ($usage, $documentsMetric, $databaseIdDocumentsMetric, $databaseIdCollectionIdDocumentsMetric) {
                    $value = 1;

                    if (str_starts_with($document->getCollection(), 'database_') && str_contains($document->getCollection(), '_collection_')) {
                        $parts = explode('_', $document->getCollection());
                        $databaseInternalId   = $parts[1] ?? 0;
                        $collectionInternalId = $parts[3] ?? 0;
                        $usage
                            ->addMetric($documentsMetric, $value)  // per project
                            ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, $databaseIdDocumentsMetric), $value) // per database
                            ->addMetric(str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$databaseInternalId, $collectionInternalId], $databaseIdCollectionIdDocumentsMetric), $value);  // per collection
                    }
                })
                ->on(Database::EVENT_DOCUMENT_DELETE, 'calculate-usage', function ($event, $document) use ($usage, $documentsMetric, $databaseIdDocumentsMetric, $databaseIdCollectionIdDocumentsMetric) {
                    $value = -1;

                    if (str_starts_with($document->getCollection(), 'database_') && str_contains($document->getCollection(), '_collection_')) {
                        $parts = explode('_', $document->getCollection());
                        $databaseInternalId   = $parts[1] ?? 0;
                        $collectionInternalId = $parts[3] ?? 0;
                        $usage
                            ->addMetric($documentsMetric, $value)  // per project
                            ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, $databaseIdDocumentsMetric), $value) // per database
                            ->addMetric(str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$databaseInternalId, $collectionInternalId], $databaseIdCollectionIdDocumentsMetric), $value);  // per collection
                    }
                })
                ->on(Database::EVENT_DOCUMENTS_CREATE, 'calculate-usage', function ($event, $document) use ($usage, $documentsMetric, $databaseIdDocumentsMetric, $databaseIdCollectionIdDocumentsMetric) {
                    $value = $document->getAttribute('modified', 0);

                    if (str_starts_with($document->getCollection(), 'database_') && str_contains($document->getCollection(), '_collection_')) {
                        $parts = explode('_', $document->getCollection());
                        $databaseInternalId   = $parts[1] ?? 0;
                        $collectionInternalId = $parts[3] ?? 0;
                        $usage
                            ->addMetric($documentsMetric, $value)  // per project
                            ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, $databaseIdDocumentsMetric), $value) // per database
                            ->addMetric(str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$databaseInternalId, $collectionInternalId], $databaseIdCollectionIdDocumentsMetric), $value);  // per collection
                    }
                })
                ->on(Database::EVENT_DOCUMENTS_DELETE, 'calculate-usage', function ($event, $document) use ($usage, $documentsMetric, $databaseIdDocumentsMetric, $databaseIdCollectionIdDocumentsMetric) {
                    $value = -1 * $document->getAttribute('modified', 0);

                    if (str_starts_with($document->getCollection(), 'database_') && str_contains($document->getCollection(), '_collection_')) {
                        $parts = explode('_', $document->getCollection());
                        $databaseInternalId   = $parts[1] ?? 0;
                        $collectionInternalId = $parts[3] ?? 0;
                        $usage
                            ->addMetric($documentsMetric, $value)  // per project
                            ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, $databaseIdDocumentsMetric), $value) // per database
                            ->addMetric(str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$databaseInternalId, $collectionInternalId], $databaseIdCollectionIdDocumentsMetric), $value);  // per collection
                    }
                })
                ->on(Database::EVENT_DOCUMENTS_UPSERT, 'calculate-usage', function ($event, $document) use ($usage, $documentsMetric, $databaseIdDocumentsMetric, $databaseIdCollectionIdDocumentsMetric) {
                    $value = $document->getAttribute('created', 0);

                    if (str_starts_with($document->getCollection(), 'database_') && str_contains($document->getCollection(), '_collection_')) {
                        $parts = explode('_', $document->getCollection());
                        $databaseInternalId   = $parts[1] ?? 0;
                        $collectionInternalId = $parts[3] ?? 0;
                        $usage
                            ->addMetric($documentsMetric, $value)  // per project
                            ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, $databaseIdDocumentsMetric), $value) // per database
                            ->addMetric(str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$databaseInternalId, $collectionInternalId], $databaseIdCollectionIdDocumentsMetric), $value);  // per collection
                    }
                });

            return $database;
        };

    }, ['databaseFactory', 'project', 'request', 'usage']);

    $context->set(
        'transactionState',
        fn (Database $dbForProject, Authorization $authorization, callable $getDatabasesDB) => new TransactionState($dbForProject, $authorization, $getDatabasesDB),
        ['dbForProject', 'authorization', 'getDatabasesDB']
    );

    $context->set(
        'executionsRetentionCount',
        fn (Document $project, array $plan) => ($project->getId() === 'console' || empty($plan))
            ? 0
            : (int) ($plan['executionsRetentionCount'] ?? 100),
        ['project', 'plan']
    );

    $context->set('deviceForFiles', fn ($project, Telemetry $telemetry) => new Device\Telemetry($telemetry, getDevice(APP_STORAGE_UPLOADS . '/app-' . $project->getId())), ['project', 'telemetry']);
    $context->set('deviceForSites', fn ($project, Telemetry $telemetry) => new Device\Telemetry($telemetry, getDevice(APP_STORAGE_SITES . '/app-' . $project->getId())), ['project', 'telemetry']);
    $context->set('deviceForMigrations', fn ($project, Telemetry $telemetry) => new Device\Telemetry($telemetry, getDevice(APP_STORAGE_IMPORTS . '/app-' . $project->getId())), ['project', 'telemetry']);
    $context->set('deviceForFunctions', fn ($project, Telemetry $telemetry) => new Device\Telemetry($telemetry, getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $project->getId())), ['project', 'telemetry']);
    $context->set('deviceForBuilds', fn ($project, Telemetry $telemetry) => new Device\Telemetry($telemetry, getDevice(APP_STORAGE_BUILDS . '/app-' . $project->getId())), ['project', 'telemetry']);

    $context->set('embeddingAgent', function ($register) {
        $adapter = new AppwriteAdapter();
        $adapter->setEndpoint(System::getEnv('_APP_EMBEDDING_ENDPOINT', 'http://appwrite-embedding:3000/embed'));
        $adapter->setTimeout((int) System::getEnv('_APP_EMBEDDING_TIMEOUT', '30000'));
        return new Agent($adapter);
    }, ['register']);

    $context->set('geoRecord', function ($request, Locale $locale, callable $getGeoForIp) {
        $ip = $request->getIp();

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            Console::warning("Invalid IP address: {$ip}");
            $ip = '0.0.0.0';
        }

        return $getGeoForIp($locale, $ip);
    }, ['request', 'locale', 'getGeoForIp']);

    $context->set('getGeoForIp', function () {
        return function (Locale $locale, string $ip): GeoRecord {
            $record = null;
            $geoEndpoint = System::getEnv('_APP_GEO_ENDPOINT', '');
            $geoSecret = System::getEnv('_APP_GEO_SECRET', '');

            if (!empty($geoEndpoint) && !empty($geoSecret) && filter_var($ip, FILTER_VALIDATE_IP)) {
                try {
                    $client = new Client();
                    $client->addHeader('Authorization', 'Bearer ' . $geoSecret);
                    $client->setTimeout(3000);

                    $response = $client->fetch(\rtrim($geoEndpoint, '/') . "/ips/{$ip}", Client::METHOD_GET);
                    if ($response->getStatusCode() === 200) {
                        $body = $response->json();
                        if (\is_array($body)) {
                            $record = $body;
                        }
                    }
                } catch (\Throwable $th) {
                    Console::warning('Geo service unavailable: ' . $th->getMessage());
                }
            }

            $countryCode = \strtoupper($record['countryCode'] ?? '--');
            $continentCode = \strtoupper($record['continentCode'] ?? '--');

            $eu = \array_map('strtoupper', Config::getParam('locale-eu'));
            $currencies = Config::getParam('locale-currencies');
            $currency = null;

            if ($countryCode !== '--') {
                foreach ($currencies as $element) {
                    if (isset($element['locations'], $element['code']) && \in_array($countryCode, $element['locations'], true)) {
                        $currency = $element['code'];
                        break;
                    }
                }
            }

            $autonomousSystemNumber = $record['autonomousSystemNumber'] ?? null;

            return (new GeoRecord([
                'ip' => $ip,
                'countryCode' => $countryCode,
                'continentCode' => $continentCode,
                'eu' => $countryCode !== '--' && \in_array($countryCode, $eu, true),
                'currency' => $currency,
                'latitude' => $record['latitude'] ?? null,
                'longitude' => $record['longitude'] ?? null,
                'timeZone' => $record['timeZone'] ?? null,
                'weatherCode' => $record['weatherCode'] ?? null,
                'postalCode' => $record['postalCode'] ?? null,
                'autonomousSystemNumber' => $autonomousSystemNumber === null ? null : (string) $autonomousSystemNumber,
                'autonomousSystemOrganization' => $record['autonomousSystemOrganization'] ?? null,
                'connectionType' => $record['connection'] ?? null,
                'connectionUsageType' => $record['user'] ?? $record['type'] ?? null,
                'connectionOrganization' => $record['organization'] ?? null,
                'isp' => $record['isp'] ?? null,
            ]))
                ->setLocale($locale);
        };
    }, []);
};
