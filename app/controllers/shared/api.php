<?php

use Appwrite\Auth\Key;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Bus\Events\RequestCompleted;
use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Message\Audit as AuditMessage;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Audit;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Functions\EventProcessor;
use Appwrite\Platform\Modules\Storage\Config\CacheControl;
use Appwrite\Platform\Modules\Storage\Config\StorageCacheControl;
use Appwrite\SDK\Method;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Abuse\Abuse;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\Roles;
use Utopia\Http\Http;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Validator\WhiteList;

$parseLabel = function (string $label, array $responsePayload, array $requestParams, User $user, Document $project) {
    preg_match_all('/{(.*?)}/', $label, $matches);
    foreach ($matches[1] as $pos => $match) {
        $find = $matches[0][$pos];
        $parts = explode('.', $match);

        if (count($parts) !== 2) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, "The server encountered an error while parsing the label: $label. Please create an issue on GitHub to allow us to investigate further https://github.com/appwrite/appwrite/issues/new/choose");
        }

        $namespace = $parts[0];
        $replace = $parts[1];

        $params = match ($namespace) {
            'user' => (array) $user,
            'project' => $project->getArrayCopy(),
            'request' => $requestParams,
            default => $responsePayload,
        };

        if (array_key_exists($replace, $params)) {
            $replacement = $params[$replace];
            // Convert to string if it's not already a string
            if (! is_string($replacement)) {
                if (is_array($replacement)) {
                    $replacement = json_encode($replacement);
                } elseif (is_object($replacement) && method_exists($replacement, '__toString')) {
                    $replacement = (string) $replacement;
                } elseif (is_scalar($replacement)) {
                    $replacement = (string) $replacement;
                } else {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, "The server encountered an error while parsing the label: $label. Please create an issue on GitHub to allow us to investigate further https://github.com/appwrite/appwrite/issues/new/choose");
                }
            }
            $label = \str_replace($find, $replacement, $label);
        }
    }

    return $label;
};

Http::init()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('dbForPlatform')
    ->inject('dbForProject')
    ->inject('auditContext')
    ->inject('project')
    ->inject('user')
    ->inject('session')
    ->inject('servers')
    ->inject('mode')
    ->inject('team')
    ->inject('apiKey')
    ->inject('authorization')
    ->action(function (Http $utopia, Request $request, Database $dbForPlatform, Database $dbForProject, AuditContext $auditContext, Document $project, User $user, ?Document $session, array $servers, string $mode, Document $team, ?Key $apiKey, Authorization $authorization) {
        $route = $utopia->getRoute();
        if ($route === null) {
            throw new AppwriteException(AppwriteException::GENERAL_ROUTE_NOT_FOUND);
        }

        /**
         * Handle user authentication and session validation.
         *
         * This function follows a series of steps to determine the appropriate user session
         * based on cookies, headers, and JWT tokens.
         *
         * Process:
         *
         * Project & Role Validation:
         * 1. Check if the project is empty. If so, throw an exception.
         * 2. Get the roles configuration.
         * 3. Determine the role for the user based on the user document.
         * 4. Get the scopes for the role.
         *
         * API Key Authentication:
         * 5. If there is an API key:
         *    - Verify no user session exists simultaneously
         *    - Check if key is expired
         *    - Set role and scopes from API key
         *    - Handle special app role case
         *    - For standard keys, update last accessed time
         *
         * User Activity:
         * 6. If the project is not the console and user is not admin:
         *    - Update user's last activity timestamp
         *
         * Access Control:
         * 7. Get the method from the route
         * 8. Validate namespace permissions
         * 9. Validate scope permissions
         * 10. Check if user is blocked
         *
         * Security Checks:
         * 11. Verify password status (check if reset required)
         * 12. Validate MFA requirements:
         *     - Check if MFA is enabled
         *     - Verify email status
         *     - Verify phone status
         *     - Verify authenticator status
         * 13. Handle Multi-Factor Authentication:
         *     - Check remaining required factors
         *     - Validate factor completion
         *     - Throw exception if factors incomplete
         */

        // Step 1: Check if project is empty
        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        // Step 2: Get roles configuration
        $roles = Config::getParam('roles', []);

        // Step 3: Determine role for user
        // TODO get scopes from the identity instead of the user roles config. The identity will containn the scopes the user authorized for the access token.

        $role = $user->isEmpty()
            ? Role::guests()->toString()
            : Role::users()->toString();

        // Step 4: Get scopes for the role
        $scopes = $roles[$role]['scopes'];

        // Step 5: API Key Authentication
        if (! empty($apiKey)) {
            // Check if key is expired
            if ($apiKey->isExpired()) {
                throw new Exception(Exception::PROJECT_KEY_EXPIRED);
            }

            // Set role and scopes from API key
            $role = $apiKey->getRole();
            $scopes = $apiKey->getScopes();

            // Handle special app role case
            if ($apiKey->getRole() === User::ROLE_APPS) {
                // Disable authorization checks for project API keys
                // Dynamic supported for backwards compatibility
                if (($apiKey->getType() === API_KEY_STANDARD || $apiKey->getType() === API_KEY_EPHEMERAL || $apiKey->getType() === 'dynamic') && $apiKey->getProjectId() === $project->getId()) {
                    $authorization->setDefaultStatus(false);
                }

                $user = new User([
                    '$id' => '',
                    'status' => true,
                    'type' => ACTIVITY_TYPE_KEY_PROJECT,
                    'email' => 'app.' . $project->getId() . '@service.' . $request->getHostname(),
                    'password' => '',
                    'name' => $apiKey->getName(),
                ]);

                $auditContext->user = $user;
            }

            // For standard keys, update last accessed time
            if (\in_array($apiKey->getType(), [API_KEY_STANDARD, API_KEY_ORGANIZATION, API_KEY_ACCOUNT])) {
                $dbKey = null;
                if (! empty($apiKey->getProjectId())) {
                    $dbKey = $project->find(
                        key: 'secret',
                        find: $request->getHeader('x-appwrite-key', ''),
                        subject: 'keys'
                    );
                } elseif (! empty($apiKey->getUserId())) {
                    $dbKey = $user->find(
                        key: 'secret',
                        find: $request->getHeader('x-appwrite-key', ''),
                        subject: 'keys'
                    );
                } elseif (! empty($apiKey->getTeamId())) {
                    $dbKey = $team->find(
                        key: 'secret',
                        find: $request->getHeader('x-appwrite-key', ''),
                        subject: 'keys'
                    );
                }

                if (!$dbKey) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }

                $updates = new Document();

                $accessedAt = $dbKey->getAttribute('accessedAt', 0);

                if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_KEY_ACCESS)) > $accessedAt) {
                    $updates->setAttribute('accessedAt', DateTime::now());
                }

                $sdkValidator = new WhiteList($servers, true);
                $sdk = $request->getHeader('x-sdk-name', 'UNKNOWN');

                if ($sdk !== 'UNKNOWN' && $sdkValidator->isValid($sdk)) {
                    $sdks = $dbKey->getAttribute('sdks', []);

                    if (! in_array($sdk, $sdks)) {
                        $sdks[] = $sdk;

                        $updates->setAttribute('sdks', $sdks);
                        $updates->setAttribute('accessedAt', Datetime::now());
                    }
                }

                if (! $updates->isEmpty()) {
                    $dbForPlatform->getAuthorization()->skip(fn () => $dbForPlatform->updateDocument('keys', $dbKey->getId(), $updates));

                    if (! empty($apiKey->getProjectId())) {
                        $dbForPlatform->getAuthorization()->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));
                    } elseif (! empty($apiKey->getUserId())) {
                        $dbForPlatform->getAuthorization()->skip(fn () => $dbForPlatform->purgeCachedDocument('users', $user->getId()));
                    } elseif (! empty($apiKey->getTeamId())) {
                        $dbForPlatform->getAuthorization()->skip(fn () => $dbForPlatform->purgeCachedDocument('teams', $team->getId()));
                    }
                }

                $userClone = clone $user;
                $userClone->setAttribute('type', match ($apiKey->getType()) {
                    API_KEY_STANDARD => ACTIVITY_TYPE_KEY_PROJECT,
                    API_KEY_ACCOUNT => ACTIVITY_TYPE_KEY_ACCOUNT,
                    default => ACTIVITY_TYPE_KEY_ORGANIZATION,
                });
                $auditContext->user = $userClone;
            }

            // Apply permission
            if ($apiKey->getType() === API_KEY_ORGANIZATION) {
                $authorization->addRole(Role::team($team->getId())->toString());
                $authorization->addRole(Role::team($team->getId(), 'owner')->toString());
            } elseif ($apiKey->getType() === API_KEY_ACCOUNT) {
                $authorization->addRole(Role::user($user->getId())->toString());
                $authorization->addRole(Role::users()->toString());

                if ($user->getAttribute('emailVerification', false) || $user->getAttribute('phoneVerification', false)) {
                    $authorization->addRole(Role::user($user->getId(), Roles::DIMENSION_VERIFIED)->toString());
                    $authorization->addRole(Role::users(Roles::DIMENSION_VERIFIED)->toString());
                } else {
                    $authorization->addRole(Role::user($user->getId(), Roles::DIMENSION_UNVERIFIED)->toString());
                    $authorization->addRole(Role::users(Roles::DIMENSION_UNVERIFIED)->toString());
                }

                foreach (\array_filter($user->getAttribute('memberships', []), fn ($membership) => ($membership['confirm'] ?? false) === true) as $nodeMembership) {
                    $authorization->addRole(Role::team($nodeMembership['teamId'])->toString());
                    $authorization->addRole(Role::member($nodeMembership->getId())->toString());
                    foreach (($nodeMembership['roles'] ?? []) as $nodeRole) {
                        $authorization->addRole(Role::team($nodeMembership['teamId'], $nodeRole)->toString());
                    }
                }

                foreach ($user->getAttribute('labels', []) as $nodeLabel) {
                    $authorization->addRole('label:' . $nodeLabel);
                }
            }
        } // Admin User Authentication
        elseif (($project->getId() === 'console' && ! $team->isEmpty() && ! $user->isEmpty()) || ($project->getId() !== 'console' && ! $user->isEmpty() && $mode === APP_MODE_ADMIN)) {
            $teamId = $team->getId();
            $adminRoles = [];
            $memberships = $user->getAttribute('memberships', []);
            foreach ($memberships as $membership) {
                if ($membership->getAttribute('confirm', false) === true && $membership->getAttribute('teamId') === $teamId) {
                    $adminRoles = $membership->getAttribute('roles', []);
                    break;
                }
            }

            if (empty($adminRoles)) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }

            $projectId = $project->getId();
            if ($projectId === 'console' && str_starts_with($route->getPath(), '/v1/projects/:projectId')) {
                $uri = $request->getURI();
                $projectId = explode('/', $uri)[3];
            }

            // Base scopes for admin users to allow listing teams and projects.
            // Useful for those who have project-specific roles but don't have team-wide role.
            $scopes = ['teams.read', 'projects.read'];
            foreach ($adminRoles as $adminRole) {
                $isTeamWideRole = ! str_starts_with($adminRole, 'project-');
                $isProjectSpecificRole = $projectId !== 'console' && str_starts_with($adminRole, 'project-' . $projectId);

                if ($isTeamWideRole || $isProjectSpecificRole) {
                    $role = match (str_starts_with($adminRole, 'project-')) {
                        true => substr($adminRole, strrpos($adminRole, '-') + 1),
                        false => $adminRole,
                    };
                    $roleScopes = $roles[$role]['scopes'] ?? [];
                    $scopes = \array_merge($scopes, $roleScopes);
                    $authorization->addRole($role);
                }
            }

            /**
             * For console projects resource, we use platform DB.
             * Enabling authorization restricts admin user to the projects they have access to.
             */
            if ($project->getId() === 'console' && ($route->getPath() === '/v1/projects' || $route->getPath() === '/v1/projects/:projectId')) {
                $authorization->setDefaultStatus(true);
            } else {
                // Otherwise, disable authorization checks.
                $authorization->setDefaultStatus(false);
            }
        }

        $scopes = \array_unique($scopes);

        // Intentional: impersonators get users.read so they can discover a target user
        // before impersonation starts, and keep that access while impersonating.
        if (
            !$user->isEmpty()
            && (
                $user->getAttribute('impersonator', false)
                || $user->getAttribute('impersonatorUserId')
            )
        ) {
            $scopes[] = 'users.read';
            $scopes = \array_unique($scopes);
        }

        $authorization->addRole($role);
        foreach ($user->getRoles($authorization) as $authRole) {
            $authorization->addRole($authRole);
        }

        /**
         * We disable authorization checks above to ensure other endpoints (list teams, members, etc.) will continue working.
         * But, for actions on resources (sites, functions, etc.) in a non-console project, we explicitly check
         * whether the admin user has necessary permission on the project (sites, functions, etc. don't have permissions associated to them).
         */
        if (empty($apiKey) && ! $user->isEmpty() && $project->getId() !== 'console' && $mode === APP_MODE_ADMIN) {
            $input = new Input(Database::PERMISSION_READ, $project->getPermissionsByType(Database::PERMISSION_READ));
            $initialStatus = $authorization->getStatus();
            $authorization->enable();
            if (! $authorization->isValid($input)) {
                throw new Exception(Exception::PROJECT_NOT_FOUND);
            }
            $authorization->setStatus($initialStatus);
        }

        // Step 6: Update project and user last activity
        if ($project->getId() !== 'console') {
            $accessedAt = $project->getAttribute('accessedAt', 0);
            if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $accessedAt) {
                $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                    'accessedAt' => DateTime::now()
                ])));
            }
        }

        if (! empty($user->getId())) {
            $impersonatorUserId = $user->getAttribute('impersonatorUserId');
            $accessedAt = $user->getAttribute('accessedAt', 0);

            // Skip updating accessedAt for impersonated requests so we don't attribute activity to the target user.
            if (! $impersonatorUserId && DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_USER_ACCESS)) > $accessedAt) {
                $user->setAttribute('accessedAt', DateTime::now());

                if ($project->getId() !== 'console' && $mode !== APP_MODE_ADMIN) {
                    $dbForProject->updateDocument('users', $user->getId(), new Document([
                        'accessedAt' => $user->getAttribute('accessedAt')
                    ]));
                } else {
                    $authorization->skip(fn () => $dbForPlatform->updateDocument('users', $user->getId(), new Document([
                        'accessedAt' => $user->getAttribute('accessedAt')
                    ])));
                }
            }
        }

        // Steps 7-9: Access Control - Method, Namespace and Scope Validation
        $method = $route->getLabel('sdk', false);

        // Take the first method if there's more than one,
        // namespace can not differ between methods on the same route
        if (\is_array($method)) {
            $method = $method[0];
        }

        if (! empty($method)) {
            $namespace = \strtolower($method->getNamespace());

            if (
                array_key_exists($namespace, $project->getAttribute('services', []))
                && ! $project->getAttribute('services', [])[$namespace]
                && ! ($user->isPrivileged($authorization->getRoles()) || $user->isApp($authorization->getRoles()))
            ) {
                throw new Exception(Exception::GENERAL_SERVICE_DISABLED);
            }
        }

        // Step 8b: Check REST protocol status
        if (
            array_key_exists('rest', $project->getAttribute('apis', []))
            && ! $project->getAttribute('apis', [])['rest']
            && ! ($user->isPrivileged($authorization->getRoles()) || $user->isApp($authorization->getRoles()))
        ) {
            throw new AppwriteException(AppwriteException::GENERAL_API_DISABLED);
        }

        // Step 9: Validate scope permissions
        $allowed = (array) $route->getLabel('scope', 'none');
        if (empty(\array_intersect($allowed, $scopes))) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, $user->getAttribute('email', 'User') . ' (role: ' . \strtolower($roles[$role]['label']) . ') missing scopes (' . \json_encode($allowed) . ')');
        }

        // Step 10: Check if user is blocked
        if ($user->getAttribute('status') === false) { // Account is blocked
            throw new Exception(Exception::USER_BLOCKED);
        }

        // Step 11: Verify password status
        if ($user->getAttribute('reset')) {
            throw new Exception(Exception::USER_PASSWORD_RESET_REQUIRED);
        }

        // Step 12: Validate MFA requirements
        $mfaEnabled = $user->getAttribute('mfa', false);
        $hasVerifiedEmail = $user->getAttribute('emailVerification', false);
        $hasVerifiedPhone = $user->getAttribute('phoneVerification', false);
        $hasVerifiedAuthenticator = TOTP::getAuthenticatorFromUser($user)?->getAttribute('verified') ?? false;
        $hasMoreFactors = $hasVerifiedEmail || $hasVerifiedPhone || $hasVerifiedAuthenticator;
        $minimumFactors = ($mfaEnabled && $hasMoreFactors) ? 2 : 1;

        // Step 13: Handle Multi-Factor Authentication
        if (! in_array('mfa', $route->getGroups())) {
            if ($session && \count($session->getAttribute('factors', [])) < $minimumFactors) {
                throw new Exception(Exception::USER_MORE_FACTORS_REQUIRED);
            }
        }
    });

Http::init()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('auditContext')
    ->inject('queueForDeletes')
    ->inject('queueForDatabase')
    ->inject('usage')
    ->inject('queueForFunctions')
    ->inject('dbForProject')
    ->inject('timelimit')
    ->inject('resourceToken')
    ->inject('mode')
    ->inject('apiKey')
    ->inject('plan')
    ->inject('devKey')
    ->inject('telemetry')
    ->inject('platform')
    ->inject('authorization')
    ->inject('cacheControlForStorage')
    ->action(function (Http $utopia, Request $request, Response $response, Document $project, User $user, Event $queueForEvents, AuditContext $auditContext, Delete $queueForDeletes, EventDatabase $queueForDatabase, Context $usage, Func $queueForFunctions, Database $dbForProject, callable $timelimit, Document $resourceToken, string $mode, ?Key $apiKey, array $plan, Document $devKey, Telemetry $telemetry, array $platform, Authorization $authorization, callable $cacheControlForStorage) {

        $response->setUser($user);
        $request->setUser($user);

        $route = $utopia->getRoute();
        if ($route === null) {
            throw new AppwriteException(AppwriteException::GENERAL_ROUTE_NOT_FOUND);
        }

        $path = $route->getMatchedPath();
        $databaseType = match (true) {
            str_contains($path, '/documentsdb') => DATABASE_TYPE_DOCUMENTSDB,
            str_contains($path, '/vectorsdb') => DATABASE_TYPE_VECTORSDB,
            default => '',
        };

        /*
        * Abuse Check
        */

        $abuseKeyLabel = $route->getLabel('abuse-key', 'url:{url},ip:{ip}');
        $timeLimitArray = [];

        $abuseKeyLabel = (! is_array($abuseKeyLabel)) ? [$abuseKeyLabel] : $abuseKeyLabel;

        foreach ($abuseKeyLabel as $abuseKey) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $timeLimit = $timelimit($abuseKey, $route->getLabel('abuse-limit', 0), $route->getLabel('abuse-time', 3600));
            $timeLimit
                ->setParam('{projectId}', $project->getId())
                ->setParam('{userId}', $user->getId())
                ->setParam('{userAgent}', $request->getUserAgent(''))
                ->setParam('{ip}', $request->getIP())
                ->setParam('{url}', $request->getHostname() . $route->getPath())
                ->setParam('{method}', $request->getMethod())
                ->setParam('{chunkId}', (int) ($start / ($end + 1 - $start)));
            $timeLimitArray[] = $timeLimit;
        }

        $closestLimit = null;

        $roles = $authorization->getRoles();
        $isPrivilegedUser = $user->isPrivileged($roles);
        $isAppUser = $user->isApp($roles);

        foreach ($timeLimitArray as $timeLimit) {
            foreach ($request->getParams() as $key => $value) { // Set request params as potential abuse keys
                if (! empty($value)) {
                    $timeLimit->setParam('{param-' . $key . '}', (\is_array($value)) ? \json_encode($value) : $value);
                }
            }

            $abuse = new Abuse($timeLimit);
            $remaining = $timeLimit->remaining();

            $limit = $timeLimit->limit();
            $time = $timeLimit->time() + $route->getLabel('abuse-time', 3600);

            if ($limit && ($remaining < $closestLimit || is_null($closestLimit))) {
                $closestLimit = $remaining;
                $response
                    ->addHeader('X-RateLimit-Limit', $limit)
                    ->addHeader('X-RateLimit-Remaining', $remaining)
                    ->addHeader('X-RateLimit-Reset', $time);
            }

            $enabled = System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled';

            if (
                $enabled                // Abuse is enabled
                && ! $isAppUser          // User is not API key
                && ! $isPrivilegedUser   // User is not an admin
                && $devKey->isEmpty()  // request doesn't not contain development key
                && $abuse->check()      // Route is rate-limited
            ) {
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED);
            }
        }

        /**
         *  TODO: (@loks0n)
         *  Avoid mutating the message across file boundaries - it's difficult to reason about at scale.
         */
        /*
        * Background Jobs
        */
        $queueForEvents
            ->setEvent($route->getLabel('event', ''))
            ->setProject($project)
            ->setUser($user);

        $auditContext->mode = $mode;
        $auditContext->userAgent = $request->getUserAgent('');
        $auditContext->ip = $request->getIP();
        $auditContext->hostname = $request->getHostname();
        $auditContext->event = $route->getLabel('audits.event', '');
        $auditContext->project = $project;

        /* If a session exists, use the user associated with the session */
        if (! $user->isEmpty()) {
            $userClone = clone $user;
            // $user doesn't support `type` and can cause unintended effects.
            if (empty($user->getAttribute('type'))) {
                $userClone->setAttribute('type', $mode === APP_MODE_ADMIN ? ACTIVITY_TYPE_ADMIN : ACTIVITY_TYPE_USER);
            }
            $auditContext->user = $userClone;
        }

        /* Auto-set projects */
        $queueForDeletes->setProject($project);
        $queueForDatabase->setProject($project);
        $queueForFunctions->setProject($project);

        /* Auto-set platforms */
        $queueForFunctions->setPlatform($platform);

        $useCache = $route->getLabel('cache', false);
        $storageCacheOperationsCounter = $telemetry->createCounter('storage.cache.operations.load');
        if ($useCache) {
            $route = $utopia->match($request);
            $isImageTransformation = $route->getPath() === '/v1/storage/buckets/:bucketId/files/:fileId/preview';
            $isDisabled = isset($plan['imageTransformations']) && $plan['imageTransformations'] === -1 && ! $user->isPrivileged($authorization->getRoles());

            $key = $request->cacheIdentifier();
            Span::add('storage.cache.key', $key);
            $cacheLog = $authorization->skip(fn () => $dbForProject->getDocument('cache', $key));
            $cache = new Cache(
                new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $project->getId())
            );
            $timestamp = 60 * 60 * 24 * 180; // Temporarily increase the TTL to 180 day to ensure files in the cache are still fetched.
            $data = $cache->load($key, $timestamp);

            if (! empty($data) && ! $cacheLog->isEmpty()) {
                $cacheControl = \sprintf('private, max-age=%d', $timestamp);
                $parts = explode('/', $cacheLog->getAttribute('resourceType', ''));
                $type = $parts[0];

                if ($type === 'bucket' && (! $isImageTransformation || ! $isDisabled)) {
                    $bucketId = $parts[1] ?? null;
                    $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

                    $isToken = ! $resourceToken->isEmpty() && $resourceToken->getAttribute('bucketInternalId') === $bucket->getSequence();
                    $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

                    if ($bucket->isEmpty() || (! $bucket->getAttribute('enabled') && ! $isAppUser && ! $isPrivilegedUser)) {
                        throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                    }

                    if (! $bucket->getAttribute('transformations', true) && ! $isAppUser && ! $isPrivilegedUser) {
                        throw new Exception(Exception::STORAGE_BUCKET_TRANSFORMATIONS_DISABLED);
                    }

                    $fileSecurity = $bucket->getAttribute('fileSecurity', false);
                    $valid = $authorization->isValid(new Input(Database::PERMISSION_READ, $bucket->getRead()));
                    if (! $fileSecurity && ! $valid && ! $isToken) {
                        throw new Exception(Exception::USER_UNAUTHORIZED);
                    }

                    $parts = explode('/', $cacheLog->getAttribute('resource'));
                    $fileId = $parts[1] ?? null;

                    if ($fileSecurity && ! $valid && ! $isToken) {
                        $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
                    } else {
                        $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
                    }

                    if (! $resourceToken->isEmpty() && $resourceToken->getAttribute('fileInternalId') !== $file->getSequence()) {
                        throw new Exception(Exception::USER_UNAUTHORIZED);
                    }

                    if ($file->isEmpty()) {
                        throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
                    }
                    Span::add('storage.bucket.id', $bucketId);
                    Span::add('storage.file.id', $fileId);
                    // Do not update transformedAt if it's a console user
                    if (! $user->isPrivileged($authorization->getRoles())) {
                        $transformedAt = $file->getAttribute('transformedAt', '');
                        if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $transformedAt) {
                            $file->setAttribute('transformedAt', DateTime::now());
                            $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $file->getAttribute('bucketInternalId'), $file->getId(), new Document([
                                'transformedAt' => $file->getAttribute('transformedAt')
                            ])));
                        }
                    }

                    if ($isImageTransformation) {
                        $cacheControl = $cacheControlForStorage(new StorageCacheControl(
                            source: CacheControl::SOURCE_CACHE,
                            user: $user,
                            maxAge: $timestamp,
                            project: $project,
                            bucket: $bucket,
                            file: $file,
                            resourceToken: $resourceToken,
                            fileSecurity: $fileSecurity,
                            cacheLog: $cacheLog,
                            route: $route,
                        ));
                    }
                }

                $accessedAt = $cacheLog->getAttribute('accessedAt', '');
                if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_CACHE_UPDATE)) > $accessedAt) {
                    $authorization->skip(fn () => $dbForProject->updateDocument('cache', $cacheLog->getId(), new Document([
                        'accessedAt' => DateTime::now(),
                    ])));
                    // Refresh the filesystem file's mtime so TTL-based expiry in cache->load() stays valid
                    $cache->save($key, $data);
                }

                $response
                    ->addHeader('Cache-Control', $cacheControl)
                    ->addHeader('X-Appwrite-Cache', 'hit')
                    ->setContentType($cacheLog->getAttribute('mimeType'));
                $storageCacheOperationsCounter->add(1, ['result' => 'hit']);
                if (! $isImageTransformation || ! $isDisabled) {
                    Span::add('storage.cache.hit', true);
                    $response->send($data);
                }
            } else {
                $storageCacheOperationsCounter->add(1, ['result' => 'miss']);
                Span::add('storage.cache.hit', false);
                $response
                    ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                    ->addHeader('Pragma', 'no-cache')
                    ->addHeader('Expires', '0')
                    ->addHeader('X-Appwrite-Cache', 'miss');
            }
        }
    });

Http::init()
    ->groups(['session'])
    ->inject('user')
    ->inject('request')
    ->action(function (User $user, Request $request) {
        if (\str_contains($request->getURI(), 'oauth2')) {
            return;
        }

        if (! $user->isEmpty()) {
            throw new Exception(Exception::USER_SESSION_ALREADY_EXISTS);
        }
    });

/**
 * Limit user session
 *
 * Delete older sessions if the number of sessions have crossed
 * the session limit set for the project
 */
Http::shutdown()
    ->groups(['session'])
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->action(function (Http $utopia, Request $request, Response $response, Document $project, Database $dbForProject) {
        $sessionLimit = $project->getAttribute('auths', [])['maxSessions'] ?? 0;

        if ($sessionLimit === 0) {
            return;
        }

        $session = $response->getPayload();
        $userId = $session['userId'] ?? '';
        if (empty($userId)) {
            return;
        }

        $user = $dbForProject->getDocument('users', $userId);
        if ($user->isEmpty()) {
            return;
        }

        $sessions = $user->getAttribute('sessions', []);
        $count = \count($sessions);
        if ($count <= $sessionLimit) {
            return;
        }

        for ($i = 0; $i < ($count - $sessionLimit); $i++) {
            $session = array_shift($sessions);
            $dbForProject->deleteDocument('sessions', $session->getId());
        }

        $dbForProject->purgeCachedDocument('users', $userId);
    });

Http::shutdown()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('auditContext')
    ->inject('publisherForAudits')
    ->inject('usage')
    ->inject('publisherForUsage')
    ->inject('queueForDeletes')
    ->inject('queueForDatabase')
    ->inject('queueForFunctions')
    ->inject('queueForWebhooks')
    ->inject('queueForRealtime')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('timelimit')
    ->inject('eventProcessor')
    ->inject('bus')
    ->inject('apiKey')
    ->inject('mode')
    ->action(function (Http $utopia, Request $request, Response $response, Document $project, User $user, Event $queueForEvents, AuditContext $auditContext, Audit $publisherForAudits, Context $usage, UsagePublisher $publisherForUsage, Delete $queueForDeletes, EventDatabase $queueForDatabase, Func $queueForFunctions, Event $queueForWebhooks, Realtime $queueForRealtime, Database $dbForProject, Authorization $authorization, callable $timelimit, EventProcessor $eventProcessor, Bus $bus, ?Key $apiKey, string $mode) use ($parseLabel) {

        $responsePayload = $response->getPayload();

        if (! empty($queueForEvents->getEvent())) {
            if (empty($queueForEvents->getPayload())) {
                $queueForEvents->setPayload($responsePayload);
            }

            // Get project and function/webhook events (cached)
            $functionsEvents = $eventProcessor->getFunctionsEvents($project, $dbForProject);
            $webhooksEvents = $eventProcessor->getWebhooksEvents($project);

            // Generate events for this operation
            $generatedEvents = Event::generateEvents(
                $queueForEvents->getEvent(),
                $queueForEvents->getParams()
            );

            if ($project->getId() !== 'console') {
                $queueForRealtime
                    ->from($queueForEvents)
                    ->trigger();
            }

            // Only trigger functions if there are matching function events
            if (! empty($functionsEvents)) {
                foreach ($generatedEvents as $event) {
                    if (isset($functionsEvents[$event])) {
                        $queueForFunctions
                            ->from($queueForEvents)
                            ->trigger();
                        break;
                    }
                }
            }

            // Only trigger webhooks if there are matching webhook events
            if (! empty($webhooksEvents)) {
                foreach ($generatedEvents as $event) {
                    if (isset($webhooksEvents[$event])) {
                        $queueForWebhooks
                            ->from($queueForEvents)
                            ->trigger();
                        break;
                    }
                }
            }
        }

        $route = $utopia->getRoute();
        $requestParams = $route->getParamsValues();

        /**
         * Abuse labels
         */
        $abuseEnabled = System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled';
        $abuseResetCode = $route->getLabel('abuse-reset', []);
        $abuseResetCode = \is_array($abuseResetCode) ? $abuseResetCode : [$abuseResetCode];

        if ($abuseEnabled && \count($abuseResetCode) > 0 && \in_array($response->getStatusCode(), $abuseResetCode)) {
            $abuseKeyLabel = $route->getLabel('abuse-key', 'url:{url},ip:{ip}');
            $abuseKeyLabel = (! is_array($abuseKeyLabel)) ? [$abuseKeyLabel] : $abuseKeyLabel;

            foreach ($abuseKeyLabel as $abuseKey) {
                $start = $request->getContentRangeStart();
                $end = $request->getContentRangeEnd();
                $timeLimit = $timelimit($abuseKey, $route->getLabel('abuse-limit', 0), $route->getLabel('abuse-time', 3600));
                $timeLimit
                    ->setParam('{projectId}', $project->getId())
                    ->setParam('{userId}', $user->getId())
                    ->setParam('{userAgent}', $request->getUserAgent(''))
                    ->setParam('{ip}', $request->getIP())
                    ->setParam('{url}', $request->getHostname() . $route->getPath())
                    ->setParam('{method}', $request->getMethod())
                    ->setParam('{chunkId}', (int) ($start / ($end + 1 - $start)));

                foreach ($request->getParams() as $key => $value) { // Set request params as potential abuse keys
                    if (! empty($value)) {
                        $timeLimit->setParam('{param-' . $key . '}', (\is_array($value)) ? \json_encode($value) : $value);
                    }
                }

                $abuse = new Abuse($timeLimit);
                $abuse->reset();
            }
        }

        /**
         * Audit labels
         */
        $pattern = $route->getLabel('audits.resource', null);
        if (! empty($pattern)) {
            $resource = $parseLabel($pattern, $responsePayload, $requestParams, $user, $project);
            if (! empty($resource) && $resource !== $pattern) {
                $auditContext->resource = $resource;
            }
        }

        if (! $user->isEmpty()) {
            $userClone = clone $user;
            // $user doesn't support `type` and can cause unintended effects.
            if (empty($user->getAttribute('type'))) {
                $userClone->setAttribute('type', $mode === APP_MODE_ADMIN ? ACTIVITY_TYPE_ADMIN : ACTIVITY_TYPE_USER);
            }
            $auditContext->user = $userClone;
        } elseif ($auditContext->user === null || $auditContext->user->isEmpty()) {
            /**
             * User in the request is empty, and no user was set for auditing previously.
             * This indicates:
             * - No API Key was used.
             * - No active session exists.
             *
             * Therefore, we consider this an anonymous request and create a relevant user.
             */
            $user = new User([
                '$id' => '',
                'status' => true,
                'type' => ACTIVITY_TYPE_GUEST,
                'email' => 'guest.' . $project->getId() . '@service.' . $request->getHostname(),
                'password' => '',
                'name' => 'Guest',
            ]);

            $auditContext->user = $user;
        }

        $auditUser = $auditContext->user;
        if (! empty($auditContext->resource) && ! $auditUser->isEmpty()) {
            /**
             * audits.payload is switched to default true
             * in order to auto audit payload for all endpoints
             */
            $pattern = $route->getLabel('audits.payload', true);
            if (! empty($pattern)) {
                $auditContext->payload = $responsePayload;
            }

            $publisherForAudits->enqueue(AuditMessage::fromContext($auditContext));
        }

        if (! empty($queueForDeletes->getType())) {
            $queueForDeletes->trigger();
        }

        if (! empty($queueForDatabase->getType())) {
            $queueForDatabase->trigger();
        }

        // Cache label
        $useCache = $route->getLabel('cache', false);
        if ($useCache) {
            $resource = $resourceType = null;
            $data = $response->getPayload();
            $statusCode = $response->getStatusCode();
            if (! empty($data['payload']) && $statusCode >= 200 && $statusCode < 300) {
                $pattern = $route->getLabel('cache.resource', null);
                if (! empty($pattern)) {
                    $resource = $parseLabel($pattern, $responsePayload, $requestParams, $user, $project);
                }

                $pattern = $route->getLabel('cache.resourceType', null);
                if (! empty($pattern)) {
                    $resourceType = $parseLabel($pattern, $responsePayload, $requestParams, $user, $project);
                }

                $cache = new Cache(
                    new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $project->getId())
                );

                $key = $request->cacheIdentifier();
                $signature = md5($data['payload']);
                $cacheLog = $authorization->skip(fn () => $dbForProject->getDocument('cache', $key));
                $accessedAt = $cacheLog->getAttribute('accessedAt', 0);
                $now = DateTime::now();
                if ($cacheLog->isEmpty()) {
                    try {
                        $authorization->skip(fn () => $dbForProject->createDocument('cache', new Document([
                            '$id' => $key,
                            'resource' => $resource,
                            'resourceType' => $resourceType,
                            'mimeType' => $response->getContentType(),
                            'accessedAt' => $now,
                            'signature' => $signature,
                        ])));
                    } catch (DuplicateException) {
                        // Race condition: another concurrent request already created the cache document
                        $cacheLog = $authorization->skip(fn () => $dbForProject->getDocument('cache', $key));
                    }
                } elseif (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_CACHE_UPDATE)) > $accessedAt) {
                    $cacheLog->setAttribute('accessedAt', $now);
                    $authorization->skip(fn () => $dbForProject->updateDocument('cache', $cacheLog->getId(), new Document([
                        'accessedAt' => $cacheLog->getAttribute('accessedAt')
                    ])));
                    // Overwrite the file every APP_CACHE_UPDATE seconds to update the file modified time that is used in the TTL checks in cache->load()
                    $cache->save($key, $data['payload']);
                }

                if ($signature !== $cacheLog->getAttribute('signature')) {
                    $cache->save($key, $data['payload']);
                }
            }
        }

        if ($project->getId() !== 'console') {
            if (! $user->isPrivileged($authorization->getRoles())) {
                $bus->dispatch(new RequestCompleted(
                    project: $project->getArrayCopy(),
                    request: $request,
                    response: $response,
                ));
            }

            // Publish usage metrics if context has data
            if (! $usage->isEmpty()) {
                $metrics = $usage->getMetrics();

                // Filter out API key disabled metrics using suffix pattern matching
                $disabledMetrics = $apiKey?->getDisabledMetrics() ?? [];
                if (! empty($disabledMetrics)) {
                    $metrics = array_values(array_filter($metrics, function ($metric) use ($disabledMetrics) {
                        foreach ($disabledMetrics as $pattern) {
                            if (str_ends_with($metric['key'], $pattern)) {
                                return false;
                            }
                        }

                        return true;
                    }));
                }

                $message = new UsageMessage(
                    project: $project,
                    metrics: $metrics,
                    reduce: $usage->getReduce()
                );

                $publisherForUsage->enqueue($message);
            }
        }
    });

Http::init()
    ->groups(['usage'])
    ->action(function () {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') !== 'enabled') {
            throw new Exception(Exception::GENERAL_USAGE_DISABLED);
        }
    });
