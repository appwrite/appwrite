<?php

use Appwrite\Auth\Auth;
use Appwrite\Event\Audit;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Usage;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Request;
use Utopia\App;
use Appwrite\Extend\Exception;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

$parseLabel = function (string $label, array $responsePayload, array $requestParams, Document $user) {
    preg_match_all('/{(.*?)}/', $label, $matches);
    foreach ($matches[1] ?? [] as $pos => $match) {
        $find = $matches[0][$pos];
        $parts = explode('.', $match);

        if (count($parts) !== 2) {
            throw new Exception('Too less or too many parts', 400, Exception::GENERAL_ARGUMENT_INVALID);
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
    var_dump($document->getCollection());

    switch (true) {
        case $document->getCollection() === 'users':
            $queueForUsage->addMetric("users", $value); // per project
            break;
        case $document->getCollection() === 'teams':
            $queueForUsage->addMetric("teams", $value); // per project
            break;
        case $document->getCollection() === 'sessions':
            $queueForUsage->addMetric("sessions", $value); // per project
            break;
        case $document->getCollection() === 'databases':
            $queueForUsage->addMetric("databases", $value); // per project
            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                // Documents
                $dbDocuments      = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".documents"));
                $projectDocuments = $dbForProject->getDocument('stats', md5("_inf_documents"));
                if (!$dbDocuments->isEmpty()) {
                    $projectDocuments->setAttribute('value', $projectDocuments['value'] - $dbDocuments['value']);
                    $dbForProject->updateDocument('stats', $projectDocuments->getId(), $projectDocuments);
                }
                // Collections
                $dbCollections      = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".collections"));
                $projectCollections = $dbForProject->getDocument('stats', md5("_inf_collections"));
                if (!$dbCollections->isEmpty()) {
                    $projectCollections->setAttribute('value', $projectCollections['value'] - $dbCollections['value']);
                    $dbForProject->updateDocument('stats', $projectCollections->getId(), $projectCollections);
                }
            }
            break;
        case str_starts_with($document->getCollection(), 'database_'): // collections
            $queueForUsage->addMetric("{$document['databaseId']}" . ".collections", $value); // per database
            $queueForUsage->addMetric("collections", $value); // per project
            break;
        case $document->getCollection() === 'documents':
            $queueForUsage->addMetric("{$document['databaseId']}" . "." . "{$document['collectionId']}" . ".documents", $value);  // per collection
            $queueForUsage->addMetric("{$document['databaseId']}" . ".documents", $value);  // per database
            $queueForUsage->addMetric("documents", $value); // per project
            break;
        case $document->getCollection() === 'buckets':
            $queueForUsage->addMetric("buckets", $value); // per project
            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                // bucket Files
                $bucketFiles = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".files"));
                $projectFiles = $dbForProject->getDocument('stats', md5("_inf_files"));
                if (!$bucketFiles->isEmpty()) {
                    $projectFiles->setAttribute('value', $projectFiles['value'] - $bucketFiles['value']);
                    $dbForProject->updateDocument('stats', $projectFiles->getId(), $projectFiles);
                }
                // bucket Storage
                $bucketStorage  = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".files.storage"));
                $projectStorage = $dbForProject->getDocument('stats', md5("_inf_files.storage"));
                if (!$bucketStorage->isEmpty()) {
                    $projectStorage->setAttribute('value', $projectStorage['value'] - $bucketStorage['value']);
                    $dbForProject->updateDocument('stats', $projectStorage->getId(), $projectStorage);
                }
            }
            break;
        case str_starts_with($document->getCollection(), 'bucket_'): // files
            $queueForUsage->addMetric("{$document['bucketId']}" . ".files", $value); // per bucket
            $queueForUsage->addMetric("{$document['bucketId']}" . ".files.storage", $document->getAttribute('sizeOriginal') * $value); // per bucket
            $queueForUsage->addMetric("files", $value); // per project
            $queueForUsage->addMetric("files.storage", $document->getAttribute('sizeOriginal') * $value); // per project
            break;
        case $document->getCollection() === 'functions':
            $queueForUsage->addMetric("functions", $value); // per project
            if ($event === Database::EVENT_DOCUMENT_DELETE) {
                // Deployments Storage
                $functionDeployments = $dbForProject->getDocument('stats', md5("_inf_function." . "{$document->getId()}" . ".deployments"));
                $projectDeployments = $dbForProject->getDocument('stats', md5("_inf_deployments"));
                if (!$functionDeployments->isEmpty()) {
                    $projectDeployments->setAttribute('value', $projectDeployments['value'] - $functionDeployments['value']);
                    $projectDeployments->updateDocument('stats', $projectDeployments->getId(), $projectDeployments);
                }
                // Deployments Storage
                $functionDeploymentsStorage = $dbForProject->getDocument('stats', md5("_inf_function." . "{$document->getId()}" . ".deployments.storage"));
                $projectDeploymentsStorage = $dbForProject->getDocument('stats', md5("_inf_function.deployments.storage"));
                if (!$functionDeployments->isEmpty()) {
                    $projectDeploymentsStorage->setAttribute('value', $projectDeploymentsStorage['value'] - $functionDeploymentsStorage['value']);
                    $projectDeploymentsStorage->updateDocument('stats', $projectDeploymentsStorage->getId(), $projectDeploymentsStorage);
                }

                //TODO  needs to find a solution for builds

                // Executions
                $functionExecutions = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".executions"));
                $projectExecutions = $dbForProject->getDocument('stats', md5("_inf_executions"));
                if (!$functionExecutions->isEmpty()) {
                    $projectExecutions->setAttribute('value', $projectExecutions['value'] - $functionExecutions['value']);
                    $projectExecutions->updateDocument('stats', $projectExecutions->getId(), $projectExecutions);
                }
                // Executions Compute
                $functionExecutionsCompute = $dbForProject->getDocument('stats', md5("_inf_" . "{$document->getId()}" . ".executions.compute"));
                $projectExecutionsCompute = $dbForProject->getDocument('stats', md5("_inf_executions.compute"));
                if (!$functionExecutionsCompute->isEmpty()) {
                    $projectExecutionsCompute->setAttribute('value', $projectExecutionsCompute['value'] - $functionExecutionsCompute['value']);
                    $projectExecutionsCompute->updateDocument('stats', $projectExecutionsCompute->getId(), $projectExecutionsCompute);
                }
            }
            break;
        case $document->getCollection() === 'deployments':
            $queueForUsage->addMetric("{$document['resourceType']}" . "." . "{$document['resourceId']}" . ".deployments", $value); // per function
            $queueForUsage->addMetric("{$document['resourceType']}" . "." . "{$document['resourceId']}" . ".deployments.storage", $document->getAttribute('size') * $value); // per function
            $queueForUsage->addMetric("deployments", $value); // per project
            $queueForUsage->addMetric("deployments.storage", $document->getAttribute('size') * $value); // per project
            break;
        case $document->getCollection() === 'builds': // needs to extract functionId
            $queueForUsage->addMetric("{$document['functionId']}" . ".builds", $value); // per function
            $queueForUsage->addMetric("{$document['functionId']}" . ".builds.storage", $document->getAttribute('size') * $value); // per function
            $queueForUsage->addMetric("builds", $value); // per project
            $queueForUsage->addMetric("builds.storage", $document->getAttribute('size') * $value); // per project
            break;
        case $document->getCollection() === 'executions':
            $queueForUsage->addMetric("{$document['functionId']}" . ".executions", $value); // per function
            $queueForUsage->addMetric("{$document['functionId']}" . ".executions.compute", $document->getAttribute('duration') * $value); // per function
            $queueForUsage->addMetric("executions", $value); // per project
            $queueForUsage->addMetric("executions.compute", $document->getAttribute('duration') * $value); // per project
            break;
        default:
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
    ->inject('events')
    ->inject('audits')
    ->inject('mails')
    ->inject('deletes')
    ->inject('database')
    ->inject('dbForProject')
    ->inject('queueForUsage')
    ->inject('mode')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Document $user, Event $events, Audit $audits, Mail $mails, Delete $deletes, EventDatabase $database, Database $dbForProject, Usage $queueForUsage, string $mode) use ($databaseListener) {

        $route = $utopia->match($request);

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
            $timeLimit = new TimeLimit($abuseKey, $route->getLabel('abuse-limit', 0), $route->getLabel('abuse-time', 3600), $dbForProject);
            $timeLimit
                ->setParam('{userId}', $user->getId())
                ->setParam('{userAgent}', $request->getUserAgent(''))
                ->setParam('{ip}', $request->getIP())
                ->setParam('{url}', $request->getHostname() . $route->getPath())
                ->setParam('{method}', $request->getMethod());
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
        $events
            ->setEvent($route->getLabel('event', ''))
            ->setProject($project)
            ->setUser($user);

        $mails
            ->setProject($project)
            ->setUser($user);

        $audits
            ->setMode($mode)
            ->setUserAgent($request->getUserAgent(''))
            ->setIP($request->getIP())
            ->setEvent($route->getLabel('audits.event', ''))
            ->setProject($project)
            ->setUser($user);

        $deletes->setProject($project);
        $database->setProject($project);

        $dbForProject
            ->on(Database::EVENT_DOCUMENT_CREATE, fn ($event, Document $document) => $databaseListener($event, $document, $project, $queueForUsage, $dbForProject))
            ->on(Database::EVENT_DOCUMENT_DELETE, fn ($event, Document $document) => $databaseListener($event, $document, $project, $queueForUsage, $dbForProject))
        ;

        $useCache = $route->getLabel('cache', false);

        if ($useCache) {
            $key = md5($request->getURI() . implode('*', $request->getParams())) . '*' . APP_CACHE_BUSTER;
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

                    if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
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

                $route->setIsActive(false);
            } else {
                $response->addHeader('X-Appwrite-Cache', 'miss');
            }
        }
    });

App::shutdown()
    ->groups(['api'])
    ->inject('utopia')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('events')
    ->inject('audits')
    ->inject('deletes')
    ->inject('database')
    ->inject('dbForProject')
    ->inject('queueForFunctions')
    ->inject('queueForUsage')
    ->action(function (App $utopia, Request $request, Response $response, Document $project, Event $events, Audit $audits, Delete $deletes, EventDatabase $database, Database $dbForProject, Func $queueForFunctions, Usage $queueForUsage) use ($parseLabel) {

        $responsePayload = $response->getPayload();

        if (!empty($events->getEvent())) {
            if (empty($events->getPayload())) {
                $events->setPayload($responsePayload);
            }

            /**
             * Trigger functions.
             */
            $queueForFunctions
                ->from($events)
                ->trigger();

            /**
             * Trigger webhooks.
             */
            $events
                ->setClass(Event::WEBHOOK_CLASS_NAME)
                ->setQueue(Event::WEBHOOK_QUEUE_NAME)
                ->trigger();

            /**
             * Trigger realtime.
             */
            if ($project->getId() !== 'console') {
                $allEvents = Event::generateEvents($events->getEvent(), $events->getParams());
                $payload = new Document($events->getPayload());

                $db = $events->getContext('database');
                $collection = $events->getContext('collection');
                $bucket = $events->getContext('bucket');

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
                    payload: $events->getPayload(),
                    events: $allEvents,
                    channels: $target['channels'],
                    roles: $target['roles'],
                    options: [
                        'permissionsChanged' => $target['permissionsChanged'],
                        'userId' => $events->getParam('userId')
                    ]
                );
            }
        }

        $route = $utopia->match($request);
        $requestParams = $route->getParamsValues();
        $user = $audits->getUser();

        /**
         * Audit labels
         */
        $pattern = $route->getLabel('audits.resource', null);
        if (!empty($pattern)) {
            $resource = $parseLabel($pattern, $responsePayload, $requestParams, $user);
            if (!empty($resource) && $resource !== $pattern) {
                $audits->setResource($resource);
            }
        }

        $pattern = $route->getLabel('audits.userId', null);
        if (!empty($pattern)) {
            $userId = $parseLabel($pattern, $responsePayload, $requestParams, $user);
            $user = $dbForProject->getDocument('users', $userId);
            $audits->setUser($user);
        }

        if (!empty($audits->getResource()) && !empty($audits->getUser()->getId())) {
            /**
             * audits.payload is switched to default true
             * in order to auto audit payload for all endpoints
             */
            $pattern = $route->getLabel('audits.payload', true);
            if (!empty($pattern)) {
                $audits->setPayload($responsePayload);
            }

            foreach ($events->getParams() as $key => $value) {
                $audits->setParam($key, $value);
            }
            $audits->trigger();
        }

        if (!empty($deletes->getType())) {
            $deletes->trigger();
        }

        if (!empty($database->getType())) {
            $database->trigger();
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

                $key = md5($request->getURI() . implode('*', $request->getParams())) . '*' . APP_CACHE_BUSTER;
                $data = json_encode([
                'resourceType' => $resourceType,
                'resource' => $resource,
                'contentType' => $response->getContentType(),
                'payload' => base64_encode($data['payload']),
                ]) ;

                $signature = md5($data);
                $cacheLog  = $dbForProject->getDocument('cache', $key);
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

        if ($project->getId() && $project->getId() !== 'console') {
            $fileSize = 0;
            $file = $request->getFiles('file');

            if (!empty($file)) {
                $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];
            }

            $queueForUsage
                ->setProject($project)
                ->addMetric("network.inbound", $request->getSize() + $fileSize)
                ->addMetric("network.outbound", $response->getSize())
                ->trigger();
        }
    });
