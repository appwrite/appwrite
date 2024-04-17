<?php

use Appwrite\Auth\Auth;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Messaging;
use Appwrite\Event\Usage;
use Appwrite\Extend\Exception;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

$parseLabel = function (string $label, array $responsePayload, array $requestParams, Document $user) {
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
            $label = \str_replace($find, $params[$replace], $label);
        }
    }
    return $label;
};

$databaseListener = function (string $event, Document $document, Document $project, Usage $queueForUsage, Database $dbForProject) {

    $value = 1;
    if ($event === Database::EVENT_DOCUMENT_DELETE) {
        $value = -1;
    }

    switch (true) {
        case $document->getCollection() === 'teams':
            $queueForUsage
                ->addMetric(METRIC_TEAMS, $value); // per project
            break;
        case $document->getCollection() === 'users':
            $queueForUsage
                ->addMetric(METRIC_USERS, $value); // per project
            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForUsage
                    ->addReduce($document);
            }
            break;
        case $document->getCollection() === 'sessions': // sessions
            $queueForUsage
                ->addMetric(METRIC_SESSIONS, $value); //per project
            break;
        case $document->getCollection() === 'databases': // databases
            $queueForUsage
                ->addMetric(METRIC_DATABASES, $value); // per project

            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForUsage
                    ->addReduce($document);
            }
            break;
        case str_starts_with($document->getCollection(), 'database_') && !str_contains($document->getCollection(), 'collection'): //collections
            $parts = explode('_', $document->getCollection());
            $databaseInternalId = $parts[1] ?? 0;
            $queueForUsage
                ->addMetric(METRIC_COLLECTIONS, $value) // per project
                ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, METRIC_DATABASE_ID_COLLECTIONS), $value) // per database
            ;

            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForUsage
                    ->addReduce($document);
            }
            break;
        case str_starts_with($document->getCollection(), 'database_') && str_contains($document->getCollection(), '_collection_'): //documents
            $parts = explode('_', $document->getCollection());
            $databaseInternalId   = $parts[1] ?? 0;
            $collectionInternalId = $parts[3] ?? 0;
            $queueForUsage
                ->addMetric(METRIC_DOCUMENTS, $value)  // per project
                ->addMetric(str_replace('{databaseInternalId}', $databaseInternalId, METRIC_DATABASE_ID_DOCUMENTS), $value) // per database
                ->addMetric(str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$databaseInternalId, $collectionInternalId], METRIC_DATABASE_ID_COLLECTION_ID_DOCUMENTS), $value);  // per collection
            break;
        case $document->getCollection() === 'buckets': //buckets
            $queueForUsage
                ->addMetric(METRIC_BUCKETS, $value); // per project
            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForUsage
                    ->addReduce($document);
            }
            break;
        case str_starts_with($document->getCollection(), 'bucket_'): // files
            $parts = explode('_', $document->getCollection());
            $bucketInternalId  = $parts[1];
            $queueForUsage
                ->addMetric(METRIC_FILES, $value) // per project
                ->addMetric(METRIC_FILES_STORAGE, $document->getAttribute('sizeOriginal') * $value) // per project
                ->addMetric(str_replace('{bucketInternalId}', $bucketInternalId, METRIC_BUCKET_ID_FILES), $value) // per bucket
                ->addMetric(str_replace('{bucketInternalId}', $bucketInternalId, METRIC_BUCKET_ID_FILES_STORAGE), $document->getAttribute('sizeOriginal') * $value); // per bucket
            break;
        case $document->getCollection() === 'functions':
            $queueForUsage
                ->addMetric(METRIC_FUNCTIONS, $value); // per project

            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                $queueForUsage
                    ->addReduce($document);
            }
            break;
        case $document->getCollection() === 'deployments':
            $queueForUsage
                ->addMetric(METRIC_DEPLOYMENTS, $value) // per project
                ->addMetric(METRIC_DEPLOYMENTS_STORAGE, $document->getAttribute('size') * $value) // per project
                ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$document->getAttribute('resourceType'), $document->getAttribute('resourceInternalId')], METRIC_FUNCTION_ID_DEPLOYMENTS), $value) // per function
                ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [$document->getAttribute('resourceType'), $document->getAttribute('resourceInternalId')], METRIC_FUNCTION_ID_DEPLOYMENTS_STORAGE), $document->getAttribute('size') * $value);
            break;
        default:
            break;
    }
};

App::init()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('dbForConsole')
    ->inject('project')
    ->inject('user')
    ->inject('session')
    ->inject('servers')
    ->inject('mode')
    ->action(function (App $utopia, Request $request, Database $dbForConsole, Document $project, Document $user, ?Document $session, array $servers, string $mode) {
        $route = $utopia->getRoute();

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        /**
         * ACL Check
         */
        $role = ($user->isEmpty())
            ? Role::guests()->toString()
            : Role::users()->toString();

        // Add user roles
        $memberships = $user->find('teamId', $project->getAttribute('teamId'), 'memberships');

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
            // Do not allow API key and session to be set at the same time
            if (!$user->isEmpty()) {
                throw new Exception(Exception::USER_API_KEY_AND_SESSION_SET);
            }

            // Check if given key match project API keys
            $key = $project->find('secret', $authKey, 'keys');
            if ($key) {
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
                    throw new Exception(Exception::PROJECT_KEY_EXPIRED);
                }

                Authorization::setRole(Auth::USER_ROLE_APPS);
                Authorization::setDefaultStatus(false);  // Cancel security segmentation for API keys.

                $accessedAt = $key->getAttribute('accessedAt', '');
                if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_KEY_ACCCESS)) > $accessedAt) {
                    $key->setAttribute('accessedAt', DateTime::now());
                    $dbForConsole->updateDocument('keys', $key->getId(), $key);
                    $dbForConsole->purgeCachedDocument('projects', $project->getId());
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
                        $dbForConsole->purgeCachedDocument('projects', $project->getId());
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
                throw new Exception(Exception::GENERAL_SERVICE_DISABLED);
            }
        }
        if (!\in_array($scope, $scopes)) {
            if ($project->isEmpty()) { // Check if permission is denied because project is missing
                throw new Exception(Exception::PROJECT_NOT_FOUND);
            }

            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, $user->getAttribute('email', 'User') . ' (role: ' . \strtolower($roles[$role]['label']) . ') missing scope (' . $scope . ')');
        }

        if (false === $user->getAttribute('status')) { // Account is blocked
            throw new Exception(Exception::USER_BLOCKED);
        }

        if ($user->getAttribute('reset')) {
            throw new Exception(Exception::USER_PASSWORD_RESET_REQUIRED);
        }

        if ($mode !== APP_MODE_ADMIN && !Auth::isPrivilegedUser(Authorization::getRoles())) {
            $mfaEnabled = $user->getAttribute('mfa', false);
            $hasVerifiedEmail = $user->getAttribute('emailVerification', false);
            $hasVerifiedPhone = $user->getAttribute('phoneVerification', false);
            $hasVerifiedAuthenticator = TOTP::getAuthenticatorFromUser($user)?->getAttribute('verified') ?? false;
            $hasMoreFactors = $hasVerifiedEmail || $hasVerifiedPhone || $hasVerifiedAuthenticator;
            $minimumFactors = ($mfaEnabled && $hasMoreFactors) ? 2 : 1;

            if (!in_array('mfa', $route->getGroups())) {
                if ($session && \count($session->getAttribute('factors')) < $minimumFactors) {
                    throw new Exception(Exception::USER_MORE_FACTORS_REQUIRED);
                }
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
    ->inject('queueForEvents')
    ->inject('queueForMessaging')
    ->inject('queueForAudits')
    ->inject('queueForDeletes')
    ->inject('queueForDatabase')
    ->inject('queueForBuilds')
    ->inject('queueForUsage')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Document $user, Event $queueForEvents, Messaging $queueForMessaging, Audit $queueForAudits, Delete $queueForDeletes, EventDatabase $queueForDatabase, Build $queueForBuilds, Usage $queueForUsage, Database $dbForProject, string $mode) use ($databaseListener) {

        $route = $utopia->getRoute();

        if (
            array_key_exists('rest', $project->getAttribute('apis', []))
            && !$project->getAttribute('apis', [])['rest']
            && !(Auth::isPrivilegedUser(Authorization::getRoles()) || Auth::isAppUser(Authorization::getRoles()))
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
            $timeLimit = new TimeLimit($abuseKey, $route->getLabel('abuse-limit', 0), $route->getLabel('abuse-time', 3600), $dbForProject);
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

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        foreach ($timeLimitArray as $timeLimit) {
            foreach ($request->getParams() as $key => $value) { // Set request params as potential abuse keys
                if (!empty($value)) {
                    $timeLimit->setParam('{param-' . $key . '}', (\is_array($value)) ? \json_encode($value) : $value);
                }
            }

            $abuse = new Abuse($timeLimit);
            $remaining = $timeLimit->remaining();
            $limit = $timeLimit->limit();
            $time = (new \DateTime($timeLimit->time()))->getTimestamp() + $route->getLabel('abuse-time', 3600);

            if ($limit && ($remaining < $closestLimit || is_null($closestLimit))) {
                $closestLimit = $remaining;
                $response
                    ->addHeader('X-RateLimit-Limit', $limit)
                    ->addHeader('X-RateLimit-Remaining', $remaining)
                    ->addHeader('X-RateLimit-Reset', $time)
                ;
            }

            $enabled = System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled';

            if (
                $enabled                // Abuse is enabled
                && !$isAppUser          // User is not API key
                && !$isPrivilegedUser   // User is not an admin
                && $abuse->check()      // Route is rate-limited
            ) {
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED);
            }
        }

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
            ->setEvent($route->getLabel('audits.event', ''))
            ->setProject($project)
            ->setUser($user);

        $queueForDeletes->setProject($project);
        $queueForDatabase->setProject($project);
        $queueForBuilds->setProject($project);
        $queueForMessaging->setProject($project);

        $dbForProject
            ->on(Database::EVENT_DOCUMENT_CREATE, 'calculate-usage', fn ($event, $document) => $databaseListener($event, $document, $project, $queueForUsage, $dbForProject))
            ->on(Database::EVENT_DOCUMENT_DELETE, 'calculate-usage', fn ($event, $document) => $databaseListener($event, $document, $project, $queueForUsage, $dbForProject))
        ;

        $useCache = $route->getLabel('cache', false);
        if ($useCache) {
            $key = md5($request->getURI() . implode('*', $request->getParams()) . '*' . APP_CACHE_BUSTER);
            $cacheLog  = Authorization::skip(fn () => $dbForProject->getDocument('cache', $key));
            $cache = new Cache(
                new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $project->getId())
            );
            $timestamp = 60 * 60 * 24 * 30;
            $data = $cache->load($key, $timestamp);

            if (!empty($data) && !$cacheLog->isEmpty()) {
                $parts = explode('/', $cacheLog->getAttribute('resourceType'));
                $type = $parts[0] ?? null;

                if ($type === 'bucket') {
                    $bucketId = $parts[1] ?? null;
                    $bucket   = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

                    $isAPIKey = Auth::isAppUser(Authorization::getRoles());
                    $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

                    if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
                        throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                    }

                    $fileSecurity = $bucket->getAttribute('fileSecurity', false);
                    $validator = new Authorization(Database::PERMISSION_READ);
                    $valid = $validator->isValid($bucket->getRead());

                    if (!$fileSecurity && !$valid) {
                        throw new Exception(Exception::USER_UNAUTHORIZED);
                    }

                    $parts = explode('/', $cacheLog->getAttribute('resource'));
                    $fileId = $parts[1] ?? null;

                    if ($fileSecurity && !$valid) {
                        $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
                    } else {
                        $file = Authorization::skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
                    }

                    if ($file->isEmpty()) {
                        throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
                    }
                }

                $response
                    ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $timestamp) . ' GMT')
                    ->addHeader('X-Appwrite-Cache', 'hit')
                    ->setContentType($cacheLog->getAttribute('mimeType'))
                    ->send($data)
                ;
            } else {
                $response->addHeader('X-Appwrite-Cache', 'miss');
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
    ->inject('queueForUsage')
    ->inject('queueForDeletes')
    ->inject('queueForDatabase')
    ->inject('queueForBuilds')
    ->inject('queueForMessaging')
    ->inject('dbForProject')
    ->inject('queueForFunctions')
    ->inject('mode')
    ->inject('dbForConsole')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Document $user, Event $queueForEvents, Audit $queueForAudits, Usage $queueForUsage, Delete $queueForDeletes, EventDatabase $queueForDatabase, Build $queueForBuilds, Messaging $queueForMessaging, Database $dbForProject, Func $queueForFunctions, string $mode, Database $dbForConsole) use ($parseLabel) {

        $responsePayload = $response->getPayload();

        if (!empty($queueForEvents->getEvent())) {
            if (empty($queueForEvents->getPayload())) {
                $queueForEvents->setPayload($responsePayload);
            }

            /**
             * Trigger functions.
             */
            $queueForFunctions
                ->from($queueForEvents)
                ->trigger();

            /**
             * Trigger webhooks.
             */
            $queueForEvents
                ->setClass(Event::WEBHOOK_CLASS_NAME)
                ->setQueue(Event::WEBHOOK_QUEUE_NAME)
                ->trigger();

            /**
             * Trigger realtime.
             */
            if ($project->getId() !== 'console') {
                $allEvents = Event::generateEvents($queueForEvents->getEvent(), $queueForEvents->getParams());
                $payload = new Document($queueForEvents->getPayload());

                $db = $queueForEvents->getContext('database');
                $collection = $queueForEvents->getContext('collection');
                $bucket = $queueForEvents->getContext('bucket');

                $target = Realtime::fromPayload(
                    // Pass first, most verbose event pattern
                    event: $allEvents[0],
                    payload: $payload,
                    project: $project,
                    database: $db,
                    collection: $collection,
                    bucket: $bucket,
                );

                Realtime::send(
                    projectId: $target['projectId'] ?? $project->getId(),
                    payload: $queueForEvents->getPayload(),
                    events: $allEvents,
                    channels: $target['channels'],
                    roles: $target['roles'],
                    options: [
                        'permissionsChanged' => $target['permissionsChanged'],
                        'userId' => $queueForEvents->getParam('userId')
                    ]
                );
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
            $queueForAudits->setUser($user);
        }

        if (!empty($queueForAudits->getResource()) && !empty($queueForAudits->getUser()->getId())) {
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

        /**
         * Cache label
         */
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

                $key = md5($request->getURI() . '*' . implode('*', $request->getParams())) . '*' . APP_CACHE_BUSTER;
                $signature = md5($data['payload']);
                $cacheLog  =  Authorization::skip(fn () => $dbForProject->getDocument('cache', $key));
                $accessedAt = $cacheLog->getAttribute('accessedAt', '');
                $now = DateTime::now();
                if ($cacheLog->isEmpty()) {
                    Authorization::skip(fn () => $dbForProject->createDocument('cache', new Document([
                        '$id' => $key,
                        'resource' => $resource,
                        'resourceType' => $resourceType,
                        'mimeType' => $response->getContentType(),
                        'accessedAt' => $now,
                        'signature' => $signature,
                    ])));
                } elseif (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_CACHE_UPDATE)) > $accessedAt) {
                    $cacheLog->setAttribute('accessedAt', $now);
                    Authorization::skip(fn () => $dbForProject->updateDocument('cache', $cacheLog->getId(), $cacheLog));
                }

                if ($signature !== $cacheLog->getAttribute('signature')) {
                    $cache = new Cache(
                        new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $project->getId())
                    );
                    $cache->save($key, $data['payload']);
                }
            }
        }



        if ($project->getId() !== 'console') {
            if ($mode !== APP_MODE_ADMIN && !Auth::isPrivilegedUser(Authorization::getRoles())) {
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

        /**
         * Update user last activity
         */
        if (!$user->isEmpty()) {
            $accessedAt = $user->getAttribute('accessedAt', '');
            if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_USER_ACCCESS)) > $accessedAt) {
                $user->setAttribute('accessedAt', DateTime::now());

                if (APP_MODE_ADMIN !== $mode) {
                    $dbForProject->updateDocument('users', $user->getId(), $user);
                } else {
                    $dbForConsole->updateDocument('users', $user->getId(), $user);
                }
            }
        }
    });

App::init()
    ->groups(['usage'])
    ->action(function () {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') !== 'enabled') {
            throw new Exception(Exception::GENERAL_USAGE_DISABLED);
        }
    });
