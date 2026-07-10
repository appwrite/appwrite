<?php

use Appwrite\Auth\Key;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Bus\Events\RequestCompleted;
use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Audit as AuditMessage;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Audit;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Functions\EventProcessor;
use Appwrite\Locking\Lock;
use Appwrite\Platform\Modules\Storage\Config\CacheControl;
use Appwrite\Platform\Modules\Storage\Config\StorageCacheControl;
use Appwrite\Reference\Renderer;
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
use Utopia\Http\Route;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Validator\WhiteList;

Http::init()
    ->groups(['api'])
    ->inject('route')
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
    ->inject('lock')
    ->inject('impersonatorUser')
    ->inject('targetUser')
    ->action(function (Route $route, Request $request, Database $dbForPlatform, Database $dbForProject, AuditContext $auditContext, Document $project, User $user, ?Document $session, array $servers, string $mode, Document $team, ?Key $apiKey, Authorization $authorization, Lock $lock, Document $impersonatorUser, User $targetUser) {

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

            // Handle special key role case
            if ($apiKey->getRole() === User::ROLE_KEYS) {
                // Disable authorization checks for project API keys
                // Dynamic supported for backwards compatibility
                if (($apiKey->getType() === API_KEY_STANDARD || $apiKey->getType() === API_KEY_EPHEMERAL || $apiKey->getType() === 'dynamic') && $apiKey->getProjectId() === $project->getId()) {
                    $authorization->setDefaultStatus(false);
                }

                $user = new User([
                    '$id' => '',
                    'status' => true,
                    'type' => ACTOR_TYPE_KEY_PROJECT,
                    'email' => 'app.' . $project->getId() . '@service.' . $request->getHostname(),
                    'password' => '',
                    'name' => $apiKey->getName(),
                ]);

                $auditContext->user = $user;
            }

            // For standard keys, update last accessed time
            if (\in_array($apiKey->getType(), [API_KEY_STANDARD, API_KEY_ORGANIZATION, API_KEY_ACCOUNT])) {
                $dbKey = null;
                $keyOwnerInternalId = '';
                if (! empty($apiKey->getProjectId())) {
                    $dbKey = $project->find(
                        key: 'secret',
                        find: $request->getHeaderLine('x-appwrite-key', ''),
                        subject: 'keys'
                    );
                    $keyOwnerInternalId = (string) ($project->getSequence() ?: $project->getId());
                } elseif (! empty($apiKey->getUserId())) {
                    $dbKey = $user->find(
                        key: 'secret',
                        find: $request->getHeaderLine('x-appwrite-key', ''),
                        subject: 'keys'
                    );
                    $keyOwnerInternalId = (string) ($user->getSequence() ?: $user->getId());
                } elseif (! empty($apiKey->getTeamId())) {
                    $dbKey = $team->find(
                        key: 'secret',
                        find: $request->getHeaderLine('x-appwrite-key', ''),
                        subject: 'keys'
                    );
                    $keyOwnerInternalId = (string) ($team->getSequence() ?: $team->getId());
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
                $sdk = $request->getHeaderLine('x-sdk-name', 'UNKNOWN');

                if ($sdk !== 'UNKNOWN' && $sdkValidator->isValid($sdk)) {
                    $sdks = $dbKey->getAttribute('sdks', []);

                    if (! in_array($sdk, $sdks)) {
                        $sdks[] = $sdk;

                        $updates->setAttribute('sdks', $sdks);
                        $updates->setAttribute('accessedAt', DateTime::now());
                    }
                }

                $updatedKey = $updates->isEmpty()
                    ? null
                    : $lock->tryWithKey(
                        'lock:platform:'.$keyOwnerInternalId.':keys:'.$dbKey->getId(),
                        fn () => $authorization->skip(fn () => $dbForPlatform->updateDocument('keys', $dbKey->getId(), $updates)),
                        target: 'keys'
                    );

                if ($updatedKey instanceof Document) {
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
                    API_KEY_STANDARD => ACTOR_TYPE_KEY_PROJECT,
                    API_KEY_ACCOUNT => ACTOR_TYPE_KEY_ACCOUNT,
                    default => ACTOR_TYPE_KEY_ORGANIZATION,
                });

                if ($apiKey->getType() === API_KEY_STANDARD || $apiKey->getType() === API_KEY_ORGANIZATION) {
                    $userClone
                        ->setAttribute('$id', $dbKey->getId())
                        ->setAttribute('$sequence', $dbKey->getSequence());
                }

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
            $membershipSource = !$impersonatorUser->isEmpty() ? $targetUser : $user;
            $memberships = $membershipSource->getAttribute('memberships', []);
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
                || !$impersonatorUser->isEmpty()
            )
        ) {
            $scopes[] = 'users.read';
            $scopes = \array_unique($scopes);
        }

        $authorization->addRole($role);
        $rolesSource = $impersonatorUser->isEmpty() ? $user : $targetUser;
        foreach ($rolesSource->getRoles($authorization) as $authRole) {
            $authorization->addRole($authRole);
        }

        $isAdminProjectRequest = ! $user->isEmpty()
            && $project->getId() !== 'console'
            && $mode === APP_MODE_ADMIN;
        $isOAuthAdminKey = ! empty($apiKey)
            && $apiKey->getType() === API_KEY_OAUTH2
            && $apiKey->getRole() === User::ROLE_OWNER
            && $project->getId() !== 'console';

        if ($isOAuthAdminKey) {
            $authorization->setDefaultStatus(false);
        }

        if (!$impersonatorUser->isEmpty() && !$targetUser->isEmpty()) {
            $dbForProject->setMetadata('user', $targetUser->getId());
            $dbForPlatform->setMetadata('user', $targetUser->getId());
        }

        /**
         * We disable authorization checks above to ensure other endpoints (list teams, members, etc.) will continue working.
         * But, for actions on resources (sites, functions, etc.) in a non-console project, we explicitly check
         * whether the admin user has necessary permission on the project (sites, functions, etc. don't have permissions associated to them).
         */
        if (($isAdminProjectRequest && empty($apiKey)) || $isOAuthAdminKey) {
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
                $projectInternalId = (string) ($project->getSequence() ?: $project->getId());
                $lock->tryWithKey(
                    'lock:platform:'.$projectInternalId.':projects:'.$project->getId().':accessedAt',
                    fn () => $authorization->skip(fn () => $dbForPlatform->updateDocument(
                        'projects',
                        $project->getId(),
                        new Document(['accessedAt' => DateTime::now()])
                    )),
                    target: 'projects'
                );
            }
        }

        if (! empty($user->getId())) {
            $accessedAt = $user->getAttribute('accessedAt', 0);

            // Skip updating accessedAt for impersonated requests so we don't attribute activity to the target user.
            if ($impersonatorUser->isEmpty() && DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_USER_ACCESS)) > $accessedAt) {
                $user->setAttribute('accessedAt', DateTime::now());

                if ($project->getId() !== 'console' && $mode !== APP_MODE_ADMIN) {
                    $dbForProject->updateDocument('users', $user->getId(), new Document([
                        'accessedAt' => $user->getAttribute('accessedAt')
                    ]));
                } else {
                    $userInternalId = (string) ($user->getSequence() ?: $user->getId());
                    $lock->tryWithKey(
                        'lock:platform:'.$userInternalId.':users:'.$user->getId().':accessedAt',
                        fn () => $authorization->skip(fn () => $dbForPlatform->updateDocument(
                            'users',
                            $user->getId(),
                            new Document(['accessedAt' => $user->getAttribute('accessedAt')])
                        )),
                        target: 'users'
                    );
                }
            }
        }

        $rolesSource = $impersonatorUser->isEmpty() ? $user : $targetUser;

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
                && ! ($rolesSource->isPrivileged($authorization->getRoles()) || $rolesSource->isKey($authorization->getRoles()))
            ) {
                throw new Exception(Exception::GENERAL_SERVICE_DISABLED);
            }
        }

        // Step 8b: Check REST protocol status
        if (
            array_key_exists('rest', $project->getAttribute('apis', []))
            && ! $project->getAttribute('apis', [])['rest']
            && ! ($rolesSource->isPrivileged($authorization->getRoles()) || $rolesSource->isKey($authorization->getRoles()))
        ) {
            throw new AppwriteException(AppwriteException::GENERAL_API_DISABLED);
        }

        // Step 9: Validate scope permissions
        $allowed = (array) $route->getLabel('scope', 'none');
        if (empty(\array_intersect($allowed, $scopes))) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, $user->getAttribute('email', 'User') . ' (role: ' . \strtolower($roles[$role]['label']) . ') missing scopes (' . \json_encode($allowed) . ')');
        }

        // Step 10: Check if user is blocked
        if ($rolesSource->getAttribute('status') === false) { // Account is blocked
            throw new Exception(Exception::USER_BLOCKED);
        }

        // Step 11: Verify password status
        if ($rolesSource->getAttribute('reset')) {
            throw new Exception(Exception::USER_PASSWORD_RESET_REQUIRED);
        }

        // Step 12: Validate MFA requirements
        $mfaEnabled = $rolesSource->getAttribute('mfa', false);
        $hasVerifiedEmail = $rolesSource->getAttribute('emailVerification', false);
        $hasVerifiedPhone = $rolesSource->getAttribute('phoneVerification', false);
        $hasVerifiedAuthenticator = TOTP::getAuthenticatorFromUser($rolesSource)?->getAttribute('verified') ?? false;
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
    ->inject('route')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('timelimit')
    ->inject('devKey')
    ->inject('authorization')
    ->action(function (Route $route, Request $request, Response $response, Document $project, User $user, callable $timelimit, Document $devKey, Authorization $authorization) {
        $response->setUser($user);
        $request->setUser($user);

        $roles = $authorization->getRoles();
        $shouldCheckAbuse = System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled'
            && ! $user->isKey($roles)
            && ! $user->isPrivileged($roles)
            && $devKey->isEmpty();

        $abuseKeyLabel = $route->getLabel('abuse-key', 'url:{url},ip:{ip}');
        $abuseKeyLabel = (! is_array($abuseKeyLabel)) ? [$abuseKeyLabel] : $abuseKeyLabel;
        $closestLimit = null;

        foreach ($abuseKeyLabel as $abuseKey) {
            $isRateLimited = false;

            try {
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

                foreach ($request->getParams() as $key => $value) {
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

                if ($shouldCheckAbuse) {
                    $isRateLimited = $abuse->check();
                }
            } catch (\Throwable $th) {
                \error_log((string) $th);

                continue;
            }

            if ($isRateLimited) {
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED);
            }
        }
    });

Http::init()
    ->groups(['api'])
    ->inject('route')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('auditContext')
    ->inject('usage')
    ->inject('publisherForFunctions')
    ->inject('dbForProject')
    ->inject('resourceToken')
    ->inject('mode')
    ->inject('apiKey')
    ->inject('plan')
    ->inject('telemetry')
    ->inject('platform')
    ->inject('authorization')
    ->inject('cacheControlForStorage')
    ->inject('impersonatorUser')
    ->inject('targetUser')
    ->action(function (Route $route, Request $request, Response $response, Document $project, User $user, Event $queueForEvents, AuditContext $auditContext, Context $usage, FunctionPublisher $publisherForFunctions, Database $dbForProject, Document $resourceToken, string $mode, ?Key $apiKey, array $plan, Telemetry $telemetry, array $platform, Authorization $authorization, callable $cacheControlForStorage, Document $impersonatorUser, User $targetUser) {

        $response->setUser($targetUser);
        $response->setImpersonatorUser($impersonatorUser);
        $request->setUser($targetUser);

        $path = $route->getPath();
        $databaseType = match (true) {
            str_contains($path, '/documentsdb') => DATABASE_TYPE_DOCUMENTSDB,
            str_contains($path, '/vectorsdb') => DATABASE_TYPE_VECTORSDB,
            default => '',
        };

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
            ->setUser($targetUser);

        $auditContext->mode = $mode;
        $auditContext->userAgent = $request->getUserAgent('');
        $auditContext->ip = $request->getIP();
        $auditContext->hostname = $request->getHostname();
        $auditContext->event = $route->getLabel('audits.event', '');
        $auditContext->project = $project;
        $auditContext->impersonatorUser = $impersonatorUser->isEmpty() ? null : $impersonatorUser;

        /* If a session exists, use the target user (impersonated target or actor) for audit */
        if (! $targetUser->isEmpty()) {
            $userClone = clone $targetUser;
            // $user doesn't support `type` and can cause unintended effects.
            if (empty($targetUser->getAttribute('type'))) {
                $userClone->setAttribute('type', $mode === APP_MODE_ADMIN ? ACTOR_TYPE_ADMIN : ACTOR_TYPE_USER);
            }
            $auditContext->user = $userClone;
        }

        $rolesSource = $impersonatorUser->isEmpty() ? $user : $targetUser;

        $useCache = $route->getLabel('cache', false);
        $storageCacheOperationsCounter = $telemetry->createCounter('storage.cache.operations.load');
        if ($useCache) {
            $roles = $authorization->getRoles();
            $isAppUser = $rolesSource->isKey($roles);
            $isImageTransformation = $route->getPath() === '/v1/storage/buckets/:bucketId/files/:fileId/preview';
            $isDisabled = isset($plan['imageTransformations']) && $plan['imageTransformations'] === -1 && ! $rolesSource->isPrivileged($roles);

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
                    $isPrivilegedUser = $user->isPrivileged($roles);

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
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->action(function (Request $request, Response $response, Document $project, Database $dbForProject) {
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
    ->inject('route')
    ->inject('response')
    ->inject('project')
    ->inject('queueForEvents')
    ->inject('publisherForFunctions')
    ->inject('queueForWebhooks')
    ->inject('queueForRealtime')
    ->inject('dbForProject')
    ->inject('eventProcessor')
    ->action(function (Route $route, Response $response, Document $project, Event $queueForEvents, FunctionPublisher $publisherForFunctions, Event $queueForWebhooks, Realtime $queueForRealtime, Database $dbForProject, EventProcessor $eventProcessor) {
        if (empty($queueForEvents->getEvent())) {
            return;
        }

        if (empty($queueForEvents->getPayload())) {
            $queueForEvents->setPayload($response->getPayload());
        }

        // Get project and function/webhook events (cached)
        $functionsEvents = $eventProcessor->getFunctionsEvents($project, $dbForProject);
        $webhooksEvents = $eventProcessor->getWebhooksEvents($project);

        // Generate events for this operation
        $generatedEvents = Event::generateEvents(
            $queueForEvents->getEvent(),
            $queueForEvents->getParams()
        );

        $allowedOnConsole = !empty(\array_intersect($route->getGroups(), Realtime::CONSOLE_ALLOWLIST));
        if ($project->getId() !== 'console' || $allowedOnConsole) {
            $queueForRealtime
                ->from($queueForEvents)
                ->trigger();
        }

        // Only trigger functions if there are matching function events
        if (! empty($functionsEvents)) {
            foreach ($generatedEvents as $event) {
                if (isset($functionsEvents[$event])) {
                    $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
                        event: $queueForEvents->getEvent(),
                        params: $queueForEvents->getParams(),
                        project: $queueForEvents->getProject(),
                        user: $queueForEvents->getUser(),
                        userId: $queueForEvents->getUserId(),
                        payload: $queueForEvents->getPayload(),
                        platform: $queueForEvents->getPlatform(),
                    ));
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
    });

Http::shutdown()
    ->groups(['api'])
    ->inject('route')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('timelimit')
    ->action(function (Route $route, Request $request, Response $response, Document $project, User $user, callable $timelimit) {
        $abuseEnabled = System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled';
        $abuseResetCode = $route->getLabel('abuse-reset', []);
        $abuseResetCode = \is_array($abuseResetCode) ? $abuseResetCode : [$abuseResetCode];

        if (! $abuseEnabled || \count($abuseResetCode) === 0 || ! \in_array($response->getStatusCode(), $abuseResetCode)) {
            return;
        }

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
    });

Http::shutdown()
    ->groups(['api'])
    ->inject('route')
    ->inject('params')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('targetUser')
    ->inject('auditContext')
    ->inject('publisherForAudits')
    ->inject('mode')
    ->action(function (Route $route, array $params, Request $request, Response $response, Document $project, User $user, User $targetUser, AuditContext $auditContext, Audit $publisherForAudits, string $mode) {
        $responsePayload = $response->getPayload();

        $pattern = $route->getLabel('audits.resource', null);
        if (! empty($pattern)) {
            $renderer = new Renderer(new Document([
                'user' => (array) $targetUser,
                'project' => $project,
                'request' => $params,
                'response' => $responsePayload,
            ]));
            $resource = $renderer->render($pattern);
            if (! empty($resource) && $resource !== $pattern) {
                $auditContext->resource = $resource;
            }
        }

        if (! $targetUser->isEmpty()) {
            $userClone = clone $targetUser;
            // $user doesn't support `type` and can cause unintended effects.
            if (empty($targetUser->getAttribute('type'))) {
                $userClone->setAttribute('type', $mode === APP_MODE_ADMIN ? ACTOR_TYPE_ADMIN : ACTOR_TYPE_USER);
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
                'type' => ACTOR_TYPE_GUEST,
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
    });

Http::shutdown()
    ->groups(['api'])
    ->inject('route')
    ->inject('params')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('authorization')
    ->action(function (Route $route, array $params, Request $request, Response $response, Document $project, User $user, Database $dbForProject, Authorization $authorization) {
        if (! $route->getLabel('cache', false)) {
            return;
        }

        $data = $response->getPayload();
        $statusCode = $response->getStatusCode();
        if (empty($data['payload']) || $statusCode < 200 || $statusCode >= 300) {
            return;
        }

        $cache = new Cache(
            new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $project->getId())
        );
        $key = $request->cacheIdentifier();
        $signature = md5($data['payload']);
        $now = DateTime::now();
        $cacheLog = $authorization->skip(fn () => $dbForProject->getDocument('cache', $key));

        // First request for this resource: record it and persist the payload.
        if ($cacheLog->isEmpty()) {
            // Resolve resource labels lazily — only needed when creating the entry.
            $requestParams = [];
            foreach ($route->getParams() as $paramKey => $param) {
                $requestParams[$paramKey] = $params[$paramKey] ?? $request->getParam($paramKey, $param['default']);
            }

            $resourcePattern = $route->getLabel('cache.resource', null);
            $resourceTypePattern = $route->getLabel('cache.resourceType', null);

            $renderer = new Renderer(new Document([
                'user' => (array) $user,
                'project' => $project,
                'request' => $requestParams,
                'response' => $data,
            ]));

            try {
                $authorization->skip(fn () => $dbForProject->createDocument('cache', new Document([
                    '$id' => $key,
                    'resource' => empty($resourcePattern) ? null : $renderer->render($resourcePattern),
                    'resourceType' => empty($resourceTypePattern) ? null : $renderer->render($resourceTypePattern),
                    'mimeType' => $response->getContentType(),
                    'accessedAt' => $now,
                    'signature' => $signature,
                ])));
                $cache->save($key, $data['payload']);

                return;
            } catch (DuplicateException) {
                // Race condition: another concurrent request already created the entry.
                $cacheLog = $authorization->skip(fn () => $dbForProject->getDocument('cache', $key));
            }
        }

        // Existing entry: refresh the access time once per APP_CACHE_UPDATE window
        // (keeps the file mtime current for TTL checks) and re-persist on content change.
        $stale = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_CACHE_UPDATE)) > $cacheLog->getAttribute('accessedAt', 0);
        if ($stale) {
            $authorization->skip(fn () => $dbForProject->updateDocument('cache', $cacheLog->getId(), new Document([
                'accessedAt' => $now,
            ])));
        }

        if ($stale || $signature !== $cacheLog->getAttribute('signature')) {
            $cache->save($key, $data['payload']);
        }
    });

Http::shutdown()
    ->groups(['api'])
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('usage')
    ->inject('publisherForUsage')
    ->inject('authorization')
    ->inject('bus')
    ->inject('apiKey')
    ->action(function (Request $request, Response $response, Document $project, User $user, Context $usage, UsagePublisher $publisherForUsage, Authorization $authorization, Bus $bus, ?Key $apiKey) {
        if ($project->getId() === 'console') {
            return;
        }

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
    });

Http::shutdown()
    ->groups(['api'])
    ->inject('route')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForPlatform')
    ->inject('authorization')
    ->inject('apiKey')
    ->inject('mode')
    ->action(function (Route $route, Response $response, Document $project, User $user, Database $dbForPlatform, Authorization $authorization, ?Key $apiKey, string $mode) {
        /**
         * Persist completed onboarding stage after usage shutdown so a schema/write failure here
         * cannot suppress RequestCompleted or usage metrics on the same request.
         */
        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300 || $project->getId() === 'console') {
            return;
        }

        $sdkLabel = $route->getLabel('sdk', false);
        if ($sdkLabel === false || $sdkLabel === null) {
            return;
        }

        /** @var array<string, true> $onboarding */
        $onboarding = Config::getParam('onboarding', []);
        if ($onboarding === []) {
            return;
        }

        $method = null;
        if ($sdkLabel instanceof Method) {
            $key = $sdkLabel->getNamespace() . '.' . $sdkLabel->getMethodName();
            if (isset($onboarding[$key])) {
                $method = $key;
            }
        } elseif (\is_array($sdkLabel)) {
            foreach ($sdkLabel as $sdkMethod) {
                if (! $sdkMethod instanceof Method) {
                    continue;
                }
                $key = $sdkMethod->getNamespace() . '.' . $sdkMethod->getMethodName();
                if (isset($onboarding[$key])) {
                    $method = $key;
                    break;
                }
            }
        }

        if ($method === null) {
            return;
        }

        $byMethod = $project->getAttribute('onboarding', []);
        $status = \is_array($byMethod) ? ($byMethod[$method]['status'] ?? null) : null;
        if ($status === ONBOARDING_STATUS_COMPLETED || $status === ONBOARDING_STATUS_SKIPPED) {
            return;
        }

        if (! \is_array($byMethod)) {
            $byMethod = [];
        }

        $actorType = ($apiKey !== null && $apiKey->getRole() === User::ROLE_KEYS)
            ? match ($apiKey->getType()) {
                API_KEY_ACCOUNT => ACTOR_TYPE_KEY_ACCOUNT,
                API_KEY_ORGANIZATION => ACTOR_TYPE_KEY_ORGANIZATION,
                API_KEY_STANDARD, API_KEY_EPHEMERAL => ACTOR_TYPE_KEY_PROJECT,
                default => ACTOR_TYPE_KEY_PROJECT,
            }
        : (! $user->isEmpty()
            ? ($mode === APP_MODE_ADMIN ? ACTOR_TYPE_ADMIN : ACTOR_TYPE_USER)
            : ACTOR_TYPE_GUEST);
        $byMethod[$method] = [
            'status' => ONBOARDING_STATUS_COMPLETED,
            'at' => DateTime::now(),
            'actorType' => $actorType,
        ];

        try {
            $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                'onboarding' => $byMethod,
            ])));
        } catch (\Throwable) {
            // Missing `onboarding` attribute on upgraded installs must not break the request lifecycle.
        }
    });
