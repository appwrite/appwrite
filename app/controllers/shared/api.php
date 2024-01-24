<?php

use Appwrite\Auth\Auth;
use Appwrite\Event\Audit;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Usage\Stats;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Request;
use Utopia\App;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use MaxMind\Db\Reader;

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

$databaseListener = function (string $event, Document $document, Stats $usage) {
    $multiplier = 1;
    if ($event === Database::EVENT_DOCUMENT_DELETE) {
        $multiplier = -1;
    }

    $collection = $document->getCollection();
    switch ($collection) {
        case 'users':
            $usage->setParam('users.{scope}.count.total', 1 * $multiplier);
            break;
        case 'databases':
            $usage->setParam('databases.{scope}.count.total', 1 * $multiplier);
            break;
        case 'buckets':
            $usage->setParam('buckets.{scope}.count.total', 1 * $multiplier);
            break;
        case 'deployments':
            $usage->setParam('deployments.{scope}.storage.size', $document->getAttribute('size') * $multiplier);
            break;
        default:
            if (strpos($collection, 'bucket_') === 0) {
                $usage
                    ->setParam('bucketId', $document->getAttribute('bucketId'))
                    ->setParam('files.{scope}.storage.size', $document->getAttribute('sizeOriginal') * $multiplier)
                    ->setParam('files.{scope}.count.total', 1 * $multiplier);
            } elseif (strpos($collection, 'database_') === 0) {
                $usage
                    ->setParam('databaseId', $document->getAttribute('databaseId'));
                if (strpos($collection, '_collection_') !== false) {
                    $usage
                        ->setParam('collectionId', $document->getAttribute('$collectionId'))
                        ->setParam('documents.{scope}.count.total', 1 * $multiplier);
                } else {
                    $usage->setParam('collections.{scope}.count.total', 1 * $multiplier);
                }
            }
            break;
    }
};

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
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('queueForMails')
    ->inject('usage')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Document $user, Event $queueForEvents, Messaging $queueForMessaging, Audit $queueForAudits, Delete $queueForDeletes, EventDatabase $queueForDatabase, Database $dbForProject, string $mode, Mail $queueForMails, Stats $usage) use ($databaseListener) {

        $route = $utopia->getRoute();

        if ($project->isEmpty() && $route->getLabel('abuse-limit', 0) > 0) { // Abuse limit requires an active project scope
            throw new Exception(Exception::PROJECT_UNKNOWN);
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

            $enabled = App::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled';

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

        $queueForMessaging
            ->setProject($project);

        $queueForAudits
            ->setMode($mode)
            ->setUserAgent($request->getUserAgent(''))
            ->setIP($request->getIP())
            ->setEvent($route->getLabel('audits.event', ''))
            ->setProject($project)
            ->setUser($user);

        $usage
            ->setParam('projectInternalId', $project->getInternalId())
            ->setParam('projectId', $project->getId())
            ->setParam('project.{scope}.network.requests', 1)
            ->setParam('httpMethod', $request->getMethod())
            ->setParam('project.{scope}.network.inbound', 0)
            ->setParam('project.{scope}.network.outbound', 0);

        $queueForDeletes->setProject($project);
        $queueForDatabase->setProject($project);

        $dbForProject->on(Database::EVENT_DOCUMENT_CREATE, 'calculate-usage', fn ($event, Document $document) => $databaseListener($event, $document, $usage));
        $dbForProject->on(Database::EVENT_DOCUMENT_DELETE, 'calculate-usage', fn ($event, Document $document) => $databaseListener($event, $document, $usage));

        $useCache = $route->getLabel('cache', false);

        if ($useCache) {
            $key = md5($request->getURI() . implode('*', $request->getParams()) . '*' . APP_CACHE_BUSTER);
            $cache = new Cache(
                new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-' . $project->getId())
            );
            $timestamp = 60 * 60 * 24 * 30;
            $data = $cache->load($key, $timestamp);

            if (!empty($data)) {
                $data = json_decode($data, true);
                $parts = explode('/', $data['resourceType']);
                $type = $parts[0] ?? null;

                if ($type === 'bucket') {
                    $bucketId = $parts[1] ?? null;

                    $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

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

                    $parts = explode('/', $data['resource']);
                    $fileId = $parts[1] ?? null;

                    if ($fileSecurity && !$valid) {
                        $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
                    } else {
                        $file = Authorization::skip(fn() => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
                    }

                    if ($file->isEmpty()) {
                        throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
                    }
                }

                $response
                    ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $timestamp) . ' GMT')
                    ->addHeader('X-Appwrite-Cache', 'hit')
                    ->setContentType($data['contentType'])
                    ->send(base64_decode($data['payload']))
                ;
            } else {
                $response->addHeader('X-Appwrite-Cache', 'miss');
            }
        }
    });

App::init()
    ->groups(['auth'])
    ->inject('utopia')
    ->inject('request')
    ->inject('project')
    ->action(function (App $utopia, Request $request, Document $project) {

        $route = $utopia->getRoute();

        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());
        $isAppUser = Auth::isAppUser(Authorization::getRoles());

        if ($isAppUser || $isPrivilegedUser) { // Skip limits for app and console devs
            return;
        }

        $auths = $project->getAttribute('auths', []);
        switch ($route->getLabel('auth.type', '')) {
            case 'emailPassword':
                if (($auths['emailPassword'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Email / Password authentication is disabled for this project');
                }
                break;

            case 'magic-url':
                if ($project->getAttribute('usersAuthMagicURL', true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Magic URL authentication is disabled for this project');
                }
                break;

            case 'anonymous':
                if (($auths['anonymous'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Anonymous authentication is disabled for this project');
                }
                break;

            case 'invites':
                if (($auths['invites'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Invites authentication is disabled for this project');
                }
                break;

            case 'jwt':
                if (($auths['JWT'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'JWT authentication is disabled for this project');
                }
                break;

            case 'phone':
                if (($auths['phone'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Phone authentication is disabled for this project');
                }
                break;

            default:
                throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Unsupported authentication route');
                break;
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
        $dbForProject->deleteCachedDocument('users', $userId);
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
    ->inject('usage')
    ->inject('queueForDeletes')
    ->inject('queueForDatabase')
    ->inject('dbForProject')
    ->inject('queueForFunctions')
    ->inject('mode')
    ->inject('dbForConsole')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Document $user, Event $queueForEvents, Audit $queueForAudits, Stats $usage, Delete $queueForDeletes, EventDatabase $queueForDatabase, Database $dbForProject, Func $queueForFunctions, string $mode, Database $dbForConsole) use ($parseLabel) {

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

                $key = md5($request->getURI() . implode('*', $request->getParams()) . '*' . APP_CACHE_BUSTER);
                $data = json_encode([
                    'resourceType' => $resourceType,
                    'resource' => $resource,
                    'contentType' => $response->getContentType(),
                    'payload' => base64_encode($data['payload']),
                ]) ;

                $signature = md5($data);
                $cacheLog  = Authorization::skip(fn () => $dbForProject->getDocument('cache', $key));
                $accessedAt = $cacheLog->getAttribute('accessedAt', '');
                $now = DateTime::now();
                if ($cacheLog->isEmpty()) {
                    Authorization::skip(fn () => $dbForProject->createDocument('cache', new Document([
                    '$id' => $key,
                    'resource' => $resource,
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
                    $cache->save($key, $data);
                }
            }
        }

        if (
            App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled'
            && $project->getId()
            && !empty($route->getLabel('sdk.namespace', null))
        ) { // Don't calculate console usage on admin mode
            $metric = $route->getLabel('usage.metric', '');
            $usageParams = $route->getLabel('usage.params', []);

            if (!empty($metric)) {
                $usage->setParam($metric, 1);
                foreach ($usageParams as $param) {
                    $param = $parseLabel($param, $responsePayload, $requestParams, $user);
                    $parts = explode(':', $param);
                    if (count($parts) != 2) {
                        throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Usage params not properly set');
                    }
                    $usage->setParam($parts[0], $parts[1]);
                }
            }

            $fileSize = 0;
            $file = $request->getFiles('file');
            if (!empty($file)) {
                $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];
            }

            $usage
                ->setParam('project.{scope}.network.inbound', $request->getSize() + $fileSize)
                ->setParam('project.{scope}.network.outbound', $response->getSize())
                ->submit();
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
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') !== 'enabled') {
            throw new Exception(Exception::GENERAL_USAGE_DISABLED);
        }
    });

App::init()
    ->groups(['restrict'])
    ->inject('request')
    ->inject('geodb')
    ->action(function (Request $request, Reader $geodb) {
        if (!empty(app::getEnv('_APP_RESTRICTED_COUNTRIES', ''))) {
            $countries = explode(',', App::getEnv('_APP_RESTRICTED_COUNTRIES', ''));
            // $record = $geodb->get($request->getIP());
            $record = $geodb->get('167.220.238.180');
            $country = $record['country']['iso_code'];
            if (in_array($country, $countries)) {
                throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, "Access from $country is restricted");
            }
        }
    });
