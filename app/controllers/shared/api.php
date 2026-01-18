<?php

use Appwrite\Auth\Key;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Event\Migration;
use Appwrite\Event\Realtime;
use Appwrite\Event\StatsUsage;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\SDK\Method;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Abuse\Abuse;
use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Queue\Publisher;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Validator\WhiteList;

$parseLabel = function (string $label, array $responsePayload, array $requestParams, User $user) {
    preg_match_all('/{(.*?)}/', $label, $matches);
    foreach ($matches[1] ?? [] as $pos => $match) {
        $find = $matches[0][$pos];
        $parts = explode('.', $match);

        if (count($parts) !== 2) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, "The server encountered an error while parsing the label: $label. Please create an issue on GitHub to allow us to investigate further https://github.com/appwrite/appwrite/issues/new/choose");
        }

        $namespace = $parts[0] ?? '';
        $replace = $parts[1] ?? '';

        $params = match ($namespace) {
            'user' => (array)$user,
            'request' => $requestParams,
            default => $responsePayload,
        };

        if (array_key_exists($replace, $params)) {
            $replacement = $params[$replace];
            // Convert to string if it's not already a string
            if (!is_string($replacement)) {
                if (is_array($replacement)) {
                    $replacement = json_encode($replacement);
                } elseif (is_object($replacement) && method_exists($replacement, '__toString')) {
                    $replacement = (string)$replacement;
                } elseif (is_scalar($replacement)) {
                    $replacement = (string)$replacement;
                } else {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, "The server encountered an error while parsing the label: $label. Please create an issue on GitHub to allow us to investigate further https://github.com/appwrite/appwrite/issues/new/choose");
                }
            }
            $label = \str_replace($find, $replacement, $label);
        }
    }
    return $label;
};

/**
 * This isolated event handling for `users.*.create` which is based on a `Database::EVENT_DOCUMENT_CREATE` listener may look odd, but it is **intentional**.
 *
 * Accounts can be created in many ways beyond `createAccount`
 * (anonymous, OAuth, phone, etc.), and those flows are probably not covered in event tests; so we handle this here.
 */
$eventDatabaseListener = function (Document $project, Document $document, Response $response, Event $queueForEvents, Func $queueForFunctions, Webhook $queueForWebhooks, Realtime $queueForRealtime) {
    // Only trigger events for user creation with the database listener.
    if ($document->getCollection() !== 'users') {
        return;
    }

    $queueForEvents
        ->setEvent('users.[userId].create')
        ->setParam('userId', $document->getId())
        ->setPayload($response->output($document, Response::MODEL_USER));

    // Trigger functions, webhooks, and realtime events
    $queueForFunctions
        ->from($queueForEvents)
        ->trigger();


    /** Trigger webhooks events only if a project has them enabled */
    if (!empty($project->getAttribute('webhooks'))) {
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

$usageDatabaseListener = function (string $event, Document $document, StatsUsage $queueForStatsUsage) {
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
            $queueForStatsUsage->addMetric(METRIC_TEAMS, $value); // per project
            break;
        case $document->getCollection() === 'users':
            $queueForStatsUsage->addMetric(METRIC_USERS, $value); // per project
            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForStatsUsage->addReduce($document);
            }
            break;
        case $document->getCollection() === 'sessions': // sessions
            $queueForStatsUsage->addMetric(METRIC_SESSIONS, $value); //per project
            break;
        case $document->getCollection() === 'databases': // databases
            $queueForStatsUsage->addMetric(METRIC_DATABASES, $value); // per project

            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForStatsUsage->addReduce($document);
            }
            break;
        case str_starts_with($document->getCollection(), 'database_') && !str_contains($document->getCollection(), 'collection'): //collections
            $parts = explode('_', $document->getCollection());
            $databaseInternalId = $parts[1] ?? 0;
            $queueForStatsUsage
                ->addMetric(METRIC_COLLECTIONS, $value) // per project
                ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, METRIC_DATABASE_ID_COLLECTIONS), $value);

            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForStatsUsage->addReduce($document);
            }
            break;
        case str_starts_with($document->getCollection(), 'database_') && str_contains($document->getCollection(), '_collection_'): //documents
            $parts = explode('_', $document->getCollection());
            $databaseInternalId   = $parts[1] ?? 0;
            $collectionInternalId = $parts[3] ?? 0;
            $queueForStatsUsage
                ->addMetric(METRIC_DOCUMENTS, $value)  // per project
                ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, METRIC_DATABASE_ID_DOCUMENTS), $value) // per database
                ->addMetric(str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$databaseInternalId, $collectionInternalId], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS), $value);  // per collection
            break;
        case $document->getCollection() === 'buckets': //buckets
            $queueForStatsUsage
                ->addMetric(METRIC_BUCKETS, $value); // per project
            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForStatsUsage
                    ->addReduce($document);
            }
            break;
        case str_starts_with($document->getCollection(), 'bucket_'): // files
            $parts = explode('_', $document->getCollection());
            $bucketInternalId  = $parts[1];
            $queueForStatsUsage
                ->addMetric(METRIC_FILES, $value) // per project
                ->addMetric(METRIC_FILES_STORAGE, $document->getAttribute('sizeOriginal') * $value) // per project
                ->addMetric(str_replace('{bucketInternalId}', $bucketInternalId, METRIC_BUCKET_ID_FILES), $value) // per bucket
                ->addMetric(str_replace('{bucketInternalId}', $bucketInternalId, METRIC_BUCKET_ID_FILES_STORAGE), $document->getAttribute('sizeOriginal') * $value); // per bucket
            break;
        case $document->getCollection() === 'functions':
            $queueForStatsUsage
                ->addMetric(METRIC_FUNCTIONS, $value); // per project

            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForStatsUsage
                    ->addReduce($document);
            }
            break;
        case $document->getCollection() === 'sites':
            $queueForStatsUsage
                ->addMetric(METRIC_SITES, $value); // per project

            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForStatsUsage
                    ->addReduce($document);
            }
            break;
        case $document->getCollection() === 'deployments':
            $queueForStatsUsage
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

App::init()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('dbForPlatform')
    ->inject('dbForProject')
    ->inject('queueForAudits')
    ->inject('project')
    ->inject('user')
    ->inject('session')
    ->inject('servers')
    ->inject('mode')
    ->inject('team')
    ->inject('apiKey')
    ->inject('authorization')
    ->action(function (App $utopia, Request $request, Database $dbForPlatform, Database $dbForProject, Audit $queueForAudits, Document $project, Document $user, ?Document $session, array $servers, string $mode, Document $team, ?Key $apiKey, Authorization $authorization) {
        $route = $utopia->getRoute();

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
        if (!empty($apiKey)) {
            // Verify no user session exists simultaneously
            if (!$user->isEmpty()) {
                throw new Exception(Exception::USER_API_KEY_AND_SESSION_SET);
            }
            // Check if key is expired
            if ($apiKey->isExpired()) {
                throw new Exception(Exception::PROJECT_KEY_EXPIRED);
            }

            // Set role and scopes from API key
            $role = $apiKey->getRole();
            $scopes = $apiKey->getScopes();


            // Handle special app role case
            if ($apiKey->getRole() === User::ROLE_APPS) {
                // Disable authorization checks for API keys
                $authorization->setDefaultStatus(false);

                $user = new User([
                    '$id' => '',
                    'status' => true,
                    'type' => ACTIVITY_TYPE_APP,
                    'email' => 'app.' . $project->getId() . '@service.' . $request->getHostname(),
                    'password' => '',
                    'name' => $apiKey->getName(),
                ]);

                $queueForAudits->setUser($user);
            }

            // For standard keys, update last accessed time
            if ($apiKey->getType() === API_KEY_STANDARD) {
                $dbKey = $project->find(
                    key: 'secret',
                    find: $request->getHeader('x-appwrite-key', ''),
                    subject: 'keys'
                );

                if (!$dbKey) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }

                $accessedAt = $dbKey->getAttribute('accessedAt', 0);

                if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_KEY_ACCESS)) > $accessedAt) {
                    $dbKey->setAttribute('accessedAt', DateTime::now());
                    $dbForPlatform->updateDocument('keys', $dbKey->getId(), $dbKey);
                    $dbForPlatform->purgeCachedDocument('projects', $project->getId());
                }

                $sdkValidator = new WhiteList($servers, true);
                $sdk = $request->getHeader('x-sdk-name', 'UNKNOWN');

                if ($sdk !== 'UNKNOWN' && $sdkValidator->isValid($sdk)) {
                    $sdks = $dbKey->getAttribute('sdks', []);

                    if (!in_array($sdk, $sdks)) {
                        $sdks[] = $sdk;
                        $dbKey->setAttribute('sdks', $sdks);

                        /** Update access time as well */
                        $dbKey->setAttribute('accessedAt', Datetime::now());
                        $dbForPlatform->updateDocument('keys', $dbKey->getId(), $dbKey);
                        $dbForPlatform->purgeCachedDocument('projects', $project->getId());
                    }
                }

                $queueForAudits->setUser($user);
            }
        } // Admin User Authentication
        elseif (($project->getId() === 'console' && !$team->isEmpty() && !$user->isEmpty()) || ($project->getId() !== 'console' && !$user->isEmpty() && $mode === APP_MODE_ADMIN)) {
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

            $scopes = []; // Reset scope if admin
            foreach ($adminRoles as $role) {
                $scopes = \array_merge($scopes, $roles[$role]['scopes']);
            }

            $authorization->setDefaultStatus(false);  // Cancel security segmentation for admin users.
        }

        $scopes = \array_unique($scopes);

        $authorization->addRole($role);
        foreach ($user->getRoles($authorization) as $authRole) {
            $authorization->addRole($authRole);
        }

        // Step 6: Update project and user last activity
        if (!$project->isEmpty() && $project->getId() !== 'console') {
            $accessedAt = $project->getAttribute('accessedAt', 0);
            if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $accessedAt) {
                $project->setAttribute('accessedAt', DateTime::now());
                $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $project));
            }
        }

        if (!empty($user->getId())) {
            $accessedAt = $user->getAttribute('accessedAt', 0);
            if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_USER_ACCESS)) > $accessedAt) {
                $user->setAttribute('accessedAt', DateTime::now());

                if (APP_MODE_ADMIN !== $mode) {
                    $dbForProject->updateDocument('users', $user->getId(), $user);
                } else {
                    $dbForPlatform->updateDocument('users', $user->getId(), $user);
                }
            }
        }

        // Steps 7-9: Access Control - Method, Namespace and Scope Validation
        /**
         * @var ?Method $method
         */
        $method = $route->getLabel('sdk', false);

        // Take the first method if there's more than one,
        // namespace can not differ between methods on the same route
        if (\is_array($method)) {
            $method = $method[0];
        }

        if (!empty($method)) {
            $namespace = $method->getNamespace();

            if (
                array_key_exists($namespace, $project->getAttribute('services', []))
                && !$project->getAttribute('services', [])[$namespace]
                && !(User::isPrivileged($authorization->getRoles()) || User::isApp($authorization->getRoles()))
            ) {
                throw new Exception(Exception::GENERAL_SERVICE_DISABLED);
            }
        }

        // Step 9: Validate scope permissions
        $allowed = (array)$route->getLabel('scope', 'none');
        if (empty(\array_intersect($allowed, $scopes))) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, $user->getAttribute('email', 'User') . ' (role: ' . \strtolower($roles[$role]['label']) . ') missing scopes (' . \json_encode($allowed) . ')');
        }

        // Step 10: Check if user is blocked
        if (false === $user->getAttribute('status')) { // Account is blocked
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
        if (!in_array('mfa', $route->getGroups())) {
            if ($session && \count($session->getAttribute('factors', [])) < $minimumFactors) {
                throw new Exception(Exception::USER_MORE_FACTORS_REQUIRED);
            }
        }
    });

App::init()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('publisher')
    ->inject('publisherFunctions')
    ->inject('publisherWebhooks')
    ->inject('queueForEvents')
    ->inject('queueForMessaging')
    ->inject('queueForAudits')
    ->inject('queueForDeletes')
    ->inject('queueForDatabase')
    ->inject('queueForBuilds')
    ->inject('queueForStatsUsage')
    ->inject('queueForFunctions')
    ->inject('queueForMails')
    ->inject('queueForMigrations')
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
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Document $user, Publisher $publisher, Publisher $publisherFunctions, Publisher $publisherWebhooks, Event $queueForEvents, Messaging $queueForMessaging, Audit $queueForAudits, Delete $queueForDeletes, EventDatabase $queueForDatabase, Build $queueForBuilds, StatsUsage $queueForStatsUsage, Func $queueForFunctions, Mail $queueForMails, Migration $queueForMigrations, Database $dbForProject, callable $timelimit, Document $resourceToken, string $mode, ?Key $apiKey, array $plan, Document $devKey, Telemetry $telemetry, array $platform, Authorization $authorization) use ($usageDatabaseListener, $eventDatabaseListener) {

        $route = $utopia->getRoute();

        if (
            array_key_exists('rest', $project->getAttribute('apis', []))
            && !$project->getAttribute('apis', [])['rest']
            && !(User::isPrivileged($authorization->getRoles()) || User::isApp($authorization->getRoles()))
        ) {
            throw new AppwriteException(AppwriteException::GENERAL_API_DISABLED);
        }

        /*
        * Abuse Check
        */
        $abuseKeyLabel = $route->getLabel('abuse-key', 'url:{url},ip:{ip}');
        $timeLimitArray = [];

        $abuseKeyLabel = (!is_array($abuseKeyLabel)) ? [$abuseKeyLabel] : $abuseKeyLabel;

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
                ->setParam('{chunkId}', (int)($start / ($end + 1 - $start)));
            $timeLimitArray[] = $timeLimit;
        }

        $closestLimit = null;

        $roles = $authorization->getRoles();
        $isPrivilegedUser = User::isPrivileged($roles);
        $isAppUser = User::isApp($roles);

        foreach ($timeLimitArray as $timeLimit) {
            foreach ($request->getParams() as $key => $value) { // Set request params as potential abuse keys
                if (!empty($value)) {
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
                && !$isAppUser          // User is not API key
                && !$isPrivilegedUser   // User is not an admin
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

        $queueForAudits
            ->setMode($mode)
            ->setUserAgent($request->getUserAgent(''))
            ->setIP($request->getIP())
            ->setHostname($request->getHostname())
            ->setEvent($route->getLabel('audits.event', ''))
            ->setProject($project);

        /* If a session exists, use the user associated with the session */
        if (!$user->isEmpty()) {
            $userClone = clone $user;
            // $user doesn't support `type` and can cause unintended effects.
            $userClone->setAttribute('type', ACTIVITY_TYPE_USER);
            $queueForAudits->setUser($userClone);
        }

        if (!empty($apiKey) && !empty($apiKey->getDisabledMetrics())) {
            foreach ($apiKey->getDisabledMetrics() as $key) {
                $queueForStatsUsage->disableMetric($key);
            }
        }

        /* Auto-set projects */
        $queueForDeletes->setProject($project);
        $queueForDatabase->setProject($project);
        $queueForMessaging->setProject($project);
        $queueForFunctions->setProject($project);
        $queueForBuilds->setProject($project);

        /* Auto-set platforms */
        $queueForFunctions->setPlatform($platform);
        $queueForBuilds->setPlatform($platform);
        $queueForMails->setPlatform($platform);

        // Clone the queues, to prevent events triggered by the database listener
        // from overwriting the events that are supposed to be triggered in the shutdown hook.
        $queueForEventsClone = new Event($publisher);
        $queueForFunctions = new Func($publisherFunctions);
        $queueForWebhooks = new Webhook($publisherWebhooks);
        $queueForRealtime = new Realtime();

        $dbForProject
            ->on(Database::EVENT_DOCUMENT_CREATE, 'calculate-usage', fn ($event, $document) => $usageDatabaseListener($event, $document, $queueForStatsUsage))
            ->on(Database::EVENT_DOCUMENT_DELETE, 'calculate-usage', fn ($event, $document) => $usageDatabaseListener($event, $document, $queueForStatsUsage))
            ->on(Database::EVENT_DOCUMENTS_CREATE, 'calculate-usage', fn ($event, $document) => $usageDatabaseListener($event, $document, $queueForStatsUsage))
            ->on(Database::EVENT_DOCUMENTS_DELETE, 'calculate-usage', fn ($event, $document) => $usageDatabaseListener($event, $document, $queueForStatsUsage))
            ->on(Database::EVENT_DOCUMENTS_UPSERT, 'calculate-usage', fn ($event, $document) => $usageDatabaseListener($event, $document, $queueForStatsUsage))
            ->on(Database::EVENT_DOCUMENT_CREATE, 'create-trigger-events', fn ($event, $document) => $eventDatabaseListener(
                $project,
                $document,
                $response,
                $queueForEventsClone->from($queueForEvents),
                $queueForFunctions->from($queueForEvents),
                $queueForWebhooks->from($queueForEvents),
                $queueForRealtime->from($queueForEvents)
            ));

        $useCache = $route->getLabel('cache', false);
        $storageCacheOperationsCounter = $telemetry->createCounter('storage.cache.operations.load');
        if ($useCache) {
            $route = $utopia->match($request);
            $isImageTransformation = $route->getPath() === '/v1/storage/buckets/:bucketId/files/:fileId/preview';
            $isDisabled = isset($plan['imageTransformations']) && $plan['imageTransformations'] === -1 && !User::isPrivileged($authorization->getRoles());

            $key = $request->cacheIdentifier();
            $cacheLog  = $authorization->skip(fn () => $dbForProject->getDocument('cache', $key));
            $cache = new Cache(
                new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $project->getId())
            );
            $timestamp = 60 * 60 * 24 * 180; // Temporarily increase the TTL to 180 day to ensure files in the cache are still fetched.
            $data = $cache->load($key, $timestamp);

            if (!empty($data) && !$cacheLog->isEmpty()) {
                $usageMetric = $route->getLabel('usage.metric', null);
                if ($usageMetric === METRIC_AVATARS_SCREENSHOTS_GENERATED) {
                    $queueForStatsUsage->disableMetric(METRIC_AVATARS_SCREENSHOTS_GENERATED);
                }
                $parts = explode('/', $cacheLog->getAttribute('resourceType', ''));
                $type = $parts[0] ?? null;

                if ($type === 'bucket' && (!$isImageTransformation || !$isDisabled)) {
                    $bucketId = $parts[1] ?? null;
                    $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

                    $isToken = !$resourceToken->isEmpty() && $resourceToken->getAttribute('bucketInternalId') === $bucket->getSequence();
                    $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

                    if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAppUser && !$isPrivilegedUser)) {
                        throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                    }

                    if (!$bucket->getAttribute('transformations', true) && !$isAppUser && !$isPrivilegedUser) {
                        throw new Exception(Exception::STORAGE_BUCKET_TRANSFORMATIONS_DISABLED);
                    }

                    $fileSecurity = $bucket->getAttribute('fileSecurity', false);
                    $valid = $authorization->isValid(new Input(Database::PERMISSION_READ, $bucket->getRead()));
                    if (!$fileSecurity && !$valid && !$isToken) {
                        throw new Exception(Exception::USER_UNAUTHORIZED);
                    }

                    $parts = explode('/', $cacheLog->getAttribute('resource'));
                    $fileId = $parts[1] ?? null;

                    if ($fileSecurity && !$valid && !$isToken) {
                        $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
                    } else {
                        $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
                    }

                    if (!$resourceToken->isEmpty() && $resourceToken->getAttribute('fileInternalId') !== $file->getSequence()) {
                        throw new Exception(Exception::USER_UNAUTHORIZED);
                    }

                    if ($file->isEmpty()) {
                        throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
                    }
                    //Do not update transformedAt if it's a console user
                    if (!User::isPrivileged($authorization->getRoles())) {
                        $transformedAt = $file->getAttribute('transformedAt', '');
                        if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $transformedAt) {
                            $file->setAttribute('transformedAt', DateTime::now());
                            $authorization->skip(fn () => $dbForProject->updateDocument('bucket_' . $file->getAttribute('bucketInternalId'), $file->getId(), $file));
                        }
                    }
                }

                $response
                    ->addHeader('Cache-Control', sprintf('private, max-age=%d', $timestamp))
                    ->addHeader('X-Appwrite-Cache', 'hit')
                    ->setContentType($cacheLog->getAttribute('mimeType'));
                $storageCacheOperationsCounter->add(1, ['result' => 'hit']);
                if (!$isImageTransformation || !$isDisabled) {
                    $response->send($data);
                }
            } else {
                $storageCacheOperationsCounter->add(1, ['result' => 'miss']);
                $response
                    ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                    ->addHeader('Pragma', 'no-cache')
                    ->addHeader('Expires', '0')
                    ->addHeader('X-Appwrite-Cache', 'miss');
            }
        }
    });

App::init()
    ->groups(['session'])
    ->inject('user')
    ->inject('request')
    ->action(function (Document $user, Request $request) {
        if (\str_contains($request->getURI(), 'oauth2')) {
            return;
        }

        if (!$user->isEmpty()) {
            throw new Exception(Exception::USER_SESSION_ALREADY_EXISTS);
        }
    });

/**
 * Limit user session
 *
 * Delete older sessions if the number of sessions have crossed
 * the session limit set for the project
 */
App::shutdown()
    ->groups(['session'])
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Database $dbForProject) {
        $sessionLimit = $project->getAttribute('auths', [])['maxSessions'] ?? APP_LIMIT_USER_SESSIONS_DEFAULT;
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

App::shutdown()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('queueForAudits')
    ->inject('queueForStatsUsage')
    ->inject('queueForDeletes')
    ->inject('queueForDatabase')
    ->inject('queueForBuilds')
    ->inject('queueForMessaging')
    ->inject('queueForFunctions')
    ->inject('queueForWebhooks')
    ->inject('queueForRealtime')
    ->inject('dbForProject')
    ->inject('authorization')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Document $user, Event $queueForEvents, Audit $queueForAudits, StatsUsage $queueForStatsUsage, Delete $queueForDeletes, EventDatabase $queueForDatabase, Build $queueForBuilds, Messaging $queueForMessaging, Func $queueForFunctions, Event $queueForWebhooks, Realtime $queueForRealtime, Database $dbForProject, Authorization $authorization) use ($parseLabel) {

        $responsePayload = $response->getPayload();

        if (!empty($queueForEvents->getEvent())) {
            if (empty($queueForEvents->getPayload())) {
                $queueForEvents->setPayload($responsePayload);
            }

            $queueForFunctions
                ->from($queueForEvents)
                ->trigger();

            if ($project->getId() !== 'console') {
                $queueForRealtime
                    ->from($queueForEvents)
                    ->trigger();
            }

            /** Trigger webhooks events only if a project has them enabled
             * A future optimisation is to only trigger webhooks if the webhook is "enabled"
             * But it might have performance implications on the API due to the number of webhooks etc.
             * Some profiling is needed to see if this is a problem.
            */
            if (!empty($project->getAttribute('webhooks'))) {
                $queueForWebhooks
                    ->from($queueForEvents)
                    ->trigger();
            }
        }

        $route = $utopia->getRoute();
        $requestParams = $route->getParamsValues();

        /**
         * Audit labels
         */
        $pattern = $route->getLabel('audits.resource', null);
        if (!empty($pattern)) {
            $resource = $parseLabel($pattern, $responsePayload, $requestParams, $user);
            if (!empty($resource) && $resource !== $pattern) {
                $queueForAudits->setResource($resource);
            }
        }

        if (!$user->isEmpty()) {
            $userClone = clone $user;
            // $user doesn't support `type` and can cause unintended effects.
            $userClone->setAttribute('type', ACTIVITY_TYPE_USER);
            $queueForAudits->setUser($userClone);
        } elseif ($queueForAudits->getUser() === null || $queueForAudits->getUser()->isEmpty()) {
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

            $queueForAudits->setUser($user);
        }

        if (!empty($queueForAudits->getResource()) && !$queueForAudits->getUser()->isEmpty()) {
            /**
             * audits.payload is switched to default true
             * in order to auto audit payload for all endpoints
             */
            $pattern = $route->getLabel('audits.payload', true);
            if (!empty($pattern)) {
                $queueForAudits->setPayload($responsePayload);
            }

            foreach ($queueForEvents->getParams() as $key => $value) {
                $queueForAudits->setParam($key, $value);
            }

            $queueForAudits->trigger();
        }

        if (!empty($queueForDeletes->getType())) {
            $queueForDeletes->trigger();
        }

        if (!empty($queueForDatabase->getType())) {
            $queueForDatabase->trigger();
        }

        if (!empty($queueForBuilds->getType())) {
            $queueForBuilds->trigger();
        }

        if (!empty($queueForMessaging->getType())) {
            $queueForMessaging->trigger();
        }

        // Cache label
        $useCache = $route->getLabel('cache', false);
        if ($useCache) {
            $resource = $resourceType = null;
            $data = $response->getPayload();
            if (!empty($data['payload'])) {
                $pattern = $route->getLabel('cache.resource', null);
                if (!empty($pattern)) {
                    $resource = $parseLabel($pattern, $responsePayload, $requestParams, $user);
                }

                $pattern = $route->getLabel('cache.resourceType', null);
                if (!empty($pattern)) {
                    $resourceType = $parseLabel($pattern, $responsePayload, $requestParams, $user);
                }

                $cache = new Cache(
                    new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $project->getId())
                );

                $key = $request->cacheIdentifier();
                $signature = md5($data['payload']);
                $cacheLog  =  $authorization->skip(fn () => $dbForProject->getDocument('cache', $key));
                $accessedAt = $cacheLog->getAttribute('accessedAt', 0);
                $now = DateTime::now();
                if ($cacheLog->isEmpty()) {
                    $authorization->skip(fn () => $dbForProject->createDocument('cache', new Document([
                        '$id' => $key,
                        'resource' => $resource,
                        'resourceType' => $resourceType,
                        'mimeType' => $response->getContentType(),
                        'accessedAt' => $now,
                        'signature' => $signature,
                    ])));
                } elseif (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_CACHE_UPDATE)) > $accessedAt) {
                    $cacheLog->setAttribute('accessedAt', $now);
                    $authorization->skip(fn () => $dbForProject->updateDocument('cache', $cacheLog->getId(), $cacheLog));
                    // Overwrite the file every APP_CACHE_UPDATE seconds to update the file modified time that is used in the TTL checks in cache->load()
                    $cache->save($key, $data['payload']);
                }

                if ($signature !== $cacheLog->getAttribute('signature')) {
                    $cache->save($key, $data['payload']);
                }
            }
        }

        if ($project->getId() !== 'console') {
            if (!User::isPrivileged($authorization->getRoles())) {
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
    });

App::init()
    ->groups(['usage'])
    ->action(function () {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') !== 'enabled') {
            throw new Exception(Exception::GENERAL_USAGE_DISABLED);
        }
    });
