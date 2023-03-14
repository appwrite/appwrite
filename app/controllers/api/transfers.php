<?php

use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Transfer;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Destinations;
use Appwrite\Utopia\Database\Validator\Queries\Sources;
use Appwrite\Utopia\Database\Validator\Queries\Transfers;
use Utopia\Validator\URL;
use Appwrite\Utopia\Response;
use Cron\CronExpression;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Transfer\Destinations\Appwrite;
use Utopia\Transfer\Sources\Appwrite as SourcesAppwrite;
use Utopia\Transfer\Sources\Firebase;
use Utopia\Transfer\Sources\NHost;
use Utopia\Transfer\Sources\Supabase;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Integer;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

include_once __DIR__ . '/../shared/api.php';

App::post('/v1/transfers')
    ->groups(['api', 'transfers'])
    ->desc('Create Transfer')
    ->label('scope', 'transfers.write')
    ->label('event', 'transfers.[transferId].create')
    ->label('audits.event', 'transfers.create')
    ->label('audits.resource', 'transfers/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/transfers/create-transfer.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TRANSFER)
    ->param('transferId', 'unique()', new CustomId(), 'Transfer unique ID. Use \'unique()\' to auto generate a unique ID for this transfer.')
    ->param('source', '', new UID(), 'Source UID. [Learn more about sources](https://appwrite.io/docs/transfers/sources)', false)
    ->param('destination', '', new UID(), 'Destination UID. [Learn more about destinations](https://appwrite.io/docs/transfers/sources)', false)
    ->param('resources', [], new ArrayList(new WhiteList(TRANSFER_RESOURCES)), 'List of resources to transfer. [A list of resources can be found here.](https://appwrite.io/docs/transfers#resources)', false)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (string $transferId, string $source, string $destination, array $resources, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $cron = !empty($schedule) ? new CronExpression($schedule) : null;
        $next = !empty($schedule) ? DateTime::format($cron->getNextRunDate()) : null;

        $transferId = ($transferId == 'unique()') ? ID::unique() : $transferId;

        $transfer = $dbForProject->createDocument('transfers', new Document([
            '$id' => $transferId,
            'status' => 'pending',
            'stage' => 'init',
            'source' => $source,
            'destination' => $destination,
            'resources' => $resources,
            'progress' => json_encode([
                'source' => [],
                'destination' => [],
            ]),
            'latestUpdate' => "{}",
            'errorData' => ""
        ]));

        $eventsInstance->setParam('transferId', $transfer->getId());

        if ($next) {
            // Async task reschedule
            $functionEvent = new Transfer();
            $functionEvent
                ->setTransfer($transfer)
                ->setType('schedule')
                ->setUser($user)
                ->setProject($project)
                ->schedule(new \DateTime($next));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($transfer, Response::MODEL_TRANSFER);
    });

App::get('/v1/transfers')
    ->groups(['api', 'transfers'])
    ->desc('List Transfers')
    ->label('scope', 'transfers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/transfers/list-transfers.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TRANSFER_LIST)
    ->param('queries', [], new Transfers(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Transfers::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $transferId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('transfers', $transferId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Transfer '{$transferId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'transfers' => $dbForProject->find('transfers', $queries),
            'total' => $dbForProject->count('transfers', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_TRANSFER_LIST);
    });

App::get('/v1/transfers/:transferId')
    ->groups(['api', 'transfers'])
    ->desc('Get Transfer')
    ->label('scope', 'transfers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/transfers/get-transfer.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TRANSFER)
    ->param('transferId', '', new UID(), 'Transfer unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $transferId, Response $response, Database $dbForProject) {
        $transfer = $dbForProject->getDocument('transfers', $transferId);

        if ($transfer->isEmpty()) {
            throw new Exception(Exception::TRANSFER_NOT_FOUND, 'Transfer not found', 404);
        }

        $response->dynamic($transfer, Response::MODEL_TRANSFER);
    });

App::delete('/v1/transfers/:transferId')
    ->groups(['api', 'transfers'])
    ->desc('Delete Transfer')
    ->label('scope', 'transfers.write')
    ->label('event', 'transfers.[transferId].delete')
    ->label('audits.event', 'transfer.delete')
    ->label('audits.resource', 'transfer/{request.transferId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/functions/delete-transfer.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('transferId', '', new UID(), 'Transfer ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deletes')
    ->inject('events')
    ->action(function (string $transferId, Response $response, Database $dbForProject, Delete $deletes, Event $events) {

        $transfer = $dbForProject->getDocument('transfers', $transferId);

        if ($transfer->isEmpty()) {
            throw new Exception(Exception::TRANSFER_NOT_FOUND, 'Transfer not found', 404);
        }

        if (!$dbForProject->deleteDocument('transfers', $transfer->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove transfer from DB', 500);
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($transfer);

        $events->setParam('transferId', $transfer->getId());

        $response->noContent();
    });

App::get('/v1/transfers/sources')
    ->groups(['api', 'transfers'])
    ->desc('List Sources')
    ->label('scope', 'transfers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'listSources')
    ->label('sdk.description', '/docs/references/transfers/list-sources.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SOURCE_LIST)
    ->param('queries', [], new Sources(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Transfers::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $sourceId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('sources', $sourceId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Source '{$sourceId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'sources' => $dbForProject->find('sources', $queries),
            'total' => $dbForProject->count('sources', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_SOURCE_LIST);
    });



App::get('/v1/transfers/sources/:sourceId')
    ->groups(['api', 'transfers'])
    ->desc('Get Source')
    ->label('scope', 'transfers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'getSource')
    ->label('sdk.description', '/docs/references/transfers/get-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SOURCE)
    ->param('sourceId', '', new UID(), 'Source unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $sourceId, Response $response, Database $dbForProject) {
        $source = $dbForProject->getDocument('sources', $sourceId);

        if ($source->isEmpty()) {
            throw new Exception(Exception::TRANSFER_SOURCE_NOT_FOUND, 'Source not found', 404);
        }

        $response->dynamic($source, Response::MODEL_SOURCE);
    });

App::post('/v1/transfers/sources/:sourceId/validate')
    ->groups(['api', 'transfers'])
    ->desc('Validate Source')
    ->label('scope', 'transfers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'validateSource')
    ->label('sdk.description', '/docs/references/transfers/validate-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SOURCE_VALIDATION)
    ->param('sourceId', '', new UID(), 'Source unique ID.')
    ->param('resources', TRANSFER_RESOURCES, new ArrayList(new WhiteList(TRANSFER_RESOURCES)), 'List of resources to test. If none are sent then all resources are tested. [A list of resources can be found here.](https://appwrite.io/docs/transfers#resources)', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $sourceId, array $resources, Response $response, Database $dbForProject) {
        $source = $dbForProject->getDocument('sources', $sourceId);

        if ($source->isEmpty()) {
            throw new Exception(Exception::TRANSFER_SOURCE_NOT_FOUND, 'Source not found', 404);
        }

        $authData = json_decode($source->getAttribute('data', "{}"), true);

        try {
            $testAdapter = null;

            switch ($source['type']) {
                case 'appwrite': {
                        $testAdapter = new SourcesAppwrite($authData['project'], $authData['endpoint'], $authData['key']);
                        break;
                    }
                case 'firebase': {
                        $testAdapter = new Firebase($authData['authObject'], Firebase::AUTH_SERVICEACCOUNT);
                        break;
                    }
                case 'supabase': {
                        $testAdapter = new Supabase($authData['url'], $authData['database'], $authData['username'], $authData['password'], $authData['port']);
                        break;
                    }
                case 'nhost': {
                        $testAdapter = new NHost($authData['url'], $authData['database'], $authData['username'], $authData['password'], $authData['port']);
                        break;
                    }
                default: {
                        throw new Exception(Exception::TRANSFER_SOURCE_NOT_FOUND, 'Source not found', 404);
                    }
            }

            $result = $testAdapter->check($resources); // Throws exception on failure

            return $response->json($result);
        } catch (Throwable $e) {
            return $response->setStatusCode(401)->dynamic(new Document([
                'success' => false,
                'message' => 'Missing Permissions',
                'errors' => [
                    'Databases' => [$e->getMessage()],
                ],
            ]), Response::MODEL_DESTINATION_VALIDATION);
        }
    });

App::delete('/v1/transfers/sources/:sourceId')
    ->groups(['api', 'transfers'])
    ->desc('Delete Source')
    ->label('scope', 'transfers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'deleteSource')
    ->label('sdk.description', '/docs/references/transfers/delete-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->param('sourceId', '', new UID(), 'Source unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $sourceId, Response $response, Database $dbForProject) {
        $source = $dbForProject->getDocument('sources', $sourceId);

        if ($source->isEmpty()) {
            throw new Exception(Exception::TRANSFER_SOURCE_NOT_FOUND, 'Source not found', 404);
        }

        $dbForProject->deleteDocument('sources', $source->getId());

        $response->noContent();
    });

App::get('/v1/transfers/destinations')
    ->groups(['api', 'transfers'])
    ->desc('List Destinations')
    ->label('scope', 'transfers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'listDestinations')
    ->label('sdk.description', '/docs/references/transfers/list-destinations.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DESTINATION_LIST)
    ->param('queries', [], new Destinations(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Transfers::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $destinationId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('destinations', $destinationId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Source '{$destinationId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'destinations' => $dbForProject->find('destinations', $queries),
            'total' => $dbForProject->count('destinations', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_DESTINATION_LIST);
    });

App::get('/v1/transfers/destinations/:destinationId')
    ->groups(['api', 'transfers'])
    ->desc('Get Destination')
    ->label('scope', 'transfers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'getDestination')
    ->label('sdk.description', '/docs/references/transfers/get-destination.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DESTINATION)
    ->param('destinationId', '', new UID(), 'Destination unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $desinationId, Response $response, Database $dbForProject) {
        $destination = $dbForProject->getDocument('destinations', $desinationId);

        if ($destination->isEmpty()) {
            throw new Exception(Exception::TRANSFER_DESTINATION_NOT_FOUND, 'Destination not found', 404);
        }

        $response->dynamic($destination, Response::MODEL_DESTINATION);
    });

App::post('/v1/transfers/destinations/:destinationId/validate')
    ->groups(['api', 'transfers'])
    ->desc('Validate Destination')
    ->label('scope', 'transfers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'validateDestination')
    ->label('sdk.description', '/docs/references/transfers/validate-destination.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DESTINATION_VALIDATION)
    ->param('destinationId', '', new UID(), 'Destination unique ID.')
    ->param('resources', TRANSFER_RESOURCES, new ArrayList(new WhiteList(TRANSFER_RESOURCES)), 'List of resources to test. If none are sent then all resources are tested. [A list of resources can be found here.](https://appwrite.io/docs/transfers#resources)', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $destinationId, array $resources, Response $response, Database $dbForProject) {
        $destination = $dbForProject->getDocument('destinations', $destinationId);

        if ($destination->isEmpty()) {
            throw new Exception(Exception::TRANSFER_DESTINATION_NOT_FOUND, 'Destination not found', 404);
        }

        $authData = json_decode($destination->getAttribute('data', "{}"), true);

        try {
            $testAdapter = null;

            switch ($destination['type']) {
                case 'appwrite': {
                        $testAdapter = new Appwrite($authData['projectId'], $authData['endpoint'], $authData['key']);
                        break;
                    }
                default: {
                        throw new Exception(Exception::TRANSFER_DESTINATION_NOT_FOUND, 'Destination not found', 404);
                    }
            }

            $result = $testAdapter->check($resources);

            $result = array_filter($result, function ($value) {
                return $value !== [];
            });

            if (count($result) == 0) {
                return $response->dynamic(new Document([
                    'success' => true,
                    'message' => 'Destination is valid'
                ]), Response::MODEL_DESTINATION_VALIDATION);
            } else {
                return $response->setStatusCode(401)->dynamic(new Document([
                    'success' => false,
                    'message' => 'Missing Permissions',
                    'errors' => $result
                ]), Response::MODEL_DESTINATION_VALIDATION);
            }
        } catch (Exception $e) {
            throw new Exception(Exception::TRANSFER_DESTINATION_FAILED, $e->getMessage(), 400);
        }
    });

App::delete('/v1/transfers/destinations/:destinationId')
    ->groups(['api', 'transfers'])
    ->desc('Delete Destination')
    ->label('scope', 'transfers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'deleteDestination')
    ->label('sdk.description', '/docs/references/transfers/delete-destination.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('destinationId', '', new UID(), 'Destination unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $destinationId, Response $response, Database $dbForProject) {
        $destination = $dbForProject->getDocument('destinations', $destinationId);

        if ($destination->isEmpty()) {
            throw new Exception(Exception::TRANSFER_DESTINATION_NOT_FOUND, 'Destination not found', 404);
        }

        $dbForProject->deleteDocument('destinations', $destination->getId());

        $response->noContent();
    });

App::post('/v1/transfers/sources/appwrite')
    ->groups(['api', 'transfers'])
    ->desc('Create Appwrite Source')
    ->label('scope', 'transfers.write')
    ->label('event', 'transfers.[sourceId].createAppwriteSource')
    ->label('audits.event', 'transfers.createAppwriteSource')
    ->label('audits.resource', 'sources/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'createAppwriteSource')
    ->label('sdk.description', '/docs/references/transfers/create-appwrite-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SOURCE)
    ->param('sourceId', 'unique()', new CustomId(), 'Source unique ID. Use \'unique()\' to auto generate a unique ID for this source.', true)
    ->param('name', '', new Text(256), 'Source Name. Max length: 256 chars.', true)
    ->param('projectId', '', new UID(), 'Source Project UID. The UID of the project to transfer.', false)
    ->param('endpoint', '', new URL(), 'Source Endpoint. The endpoint of the project to transfer.', false)
    ->param('key', '', new Text(100), 'Source Key. The key of the project to transfer.', false)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (string $sourceId, string $name, string $projectId, string $endpoint, string $key, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $sourceId = ($sourceId == 'unique()') ? ID::unique() : $sourceId;

        $testAdapter = new SourcesAppwrite($projectId, $endpoint, $key);
        $testAdapter->check(); // Throws exception on failure

        $source = $dbForProject->createDocument('sources', new Document([
            '$id' => $sourceId,
            '$collection' => ID::custom('sources'),
            'type' => 'appwrite',
            'name' => empty($name) ? 'Appwrite Project: ' . $sourceId : $name,
            'data' => json_encode([
                'projectId' => $projectId,
                'endpoint' => $endpoint,
                'key' => $key,
            ])
        ]));

        $eventsInstance->setParam('sourceId', $source->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($source, Response::MODEL_SOURCE);
    });

App::post('/v1/transfers/sources/appwrite/validate')
    ->groups(['api', 'transfers'])
    ->desc('Validate Appwrite Source')
    ->label('scope', 'transfers.write')
    ->label('audits.resource', 'sources/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'validateAppwriteSource')
    ->label('sdk.description', '/docs/references/transfers/validate-appwrite-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SOURCE_VALIDATION)
    ->param('projectId', '', new UID(), 'Source Project UID. The UID of the project to transfer.', false)
    ->param('endpoint', '', new URL(), 'Source Endpoint. The endpoint of the project to transfer.', false)
    ->param('key', '', new Text(100), 'Source Key. The key of the project to transfer.', false)
    ->inject('response')
    ->action(function (string $projectId, string $endpoint, string $key, Response $response) {
        $testAdapter = new SourcesAppwrite($projectId, $endpoint, $key);
        try {
            $testAdapter->check(); // Throws exception on failure
        } catch (Exception $e) {
            return $response->setStatusCode(401)->dynamic(new Document([
                'success' => false,
                'message' => 'Missing Permissions',
                'errors' => [
                    'Databases' => [$e->getMessage()],
                ],
            ]), Response::MODEL_DESTINATION_VALIDATION);
        }

        

        return $response->json(TRANSFER_RESOURCES);
    });

App::post('/v1/transfers/sources/firebase')
    ->groups(['api', 'transfers'])
    ->desc('Create Firebase Source')
    ->label('scope', 'transfers.write')
    ->label('event', 'transfers.[sourceId].createFirebaseSource')
    ->label('audits.event', 'transfers.createFirebaseSource')
    ->label('audits.resource', 'sources/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'createFirebaseSource')
    ->label('sdk.description', '/docs/references/transfers/create-firebase-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SOURCE)
    ->param('sourceId', 'unique()', new CustomId(), 'Source unique ID. Use \'unique()\' to auto generate a unique ID for this source.', true)
    ->param('name', '', new Text(256), 'Source Name. Max length: 256 chars.', true)
    ->param('serviceAccount', '', new JSON(), 'Firebase Service account with all required scopes, [Learn more about Firebase Transfer](https://appwrite.io/docs/transfers/sources#firebase)', false)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (string $sourceId, string $name, array $serviceAccount, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $sourceId = ($sourceId == 'unique()') ? ID::unique() : $sourceId;

        $testAdapter = new Firebase($serviceAccount, Firebase::AUTH_SERVICEACCOUNT);
        $testAdapter->check(); // Throws exception on failure

        $source = $dbForProject->createDocument('sources', new Document([
            '$id' => $sourceId,
            '$collection' => ID::custom('sources'),
            'type' => 'firebase',
            'name' => empty($name) ? 'Firebase Project ' . $serviceAccount['project_id'] : $name,
            'data' => json_encode([
                'serviceAccount' => $serviceAccount,
            ])
        ]));

        $eventsInstance->setParam('sourceId', $source->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($source, Response::MODEL_SOURCE);
    });

App::post('/v1/transfers/sources/firebase/validate')
    ->groups(['api', 'transfers'])
    ->desc('Validate Firebase Source')
    ->label('scope', 'transfers.write')
    ->label('audits.resource', 'sources/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'validateFirebaseSource')
    ->label('sdk.description', '/docs/references/transfers/validate-firebase-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SOURCE_VALIDATION)
    ->param('serviceAccount', '', new JSON(), 'Firebase Service account with all required scopes, [Learn more about Firebase Transfer](https://appwrite.io/docs/transfers/sources#firebase)', false)
    ->inject('response')
    ->inject('events')
    ->action(function (string $serviceAccount, Response $response, Event $eventsInstance) {
        $testAdapter = new Firebase(json_decode($serviceAccount, true), Firebase::AUTH_SERVICEACCOUNT);
        try {
            $testAdapter->check(); // Throws exception on failure
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 400);
        }

        $response->noContent();
    });

App::post('/v1/transfers/sources/supabase')
    ->groups(['api', 'transfers'])
    ->desc('Create Supabase Source')
    ->label('scope', 'transfers.write')
    ->label('event', 'transfers.[sourceId].createSupabaseSource')
    ->label('audits.event', 'transfers.createSupabaseSource')
    ->label('audits.resource', 'sources/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'createSupabaseSource')
    ->label('sdk.description', '/docs/references/transfers/create-supabase-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SOURCE)
    ->param('sourceId', 'unique()', new CustomId(), 'Source unique ID. Use \'unique()\' to auto generate a unique ID for this source.', true)
    ->param('name', '', new Text(256), 'Source Name. Max length: 256 chars.', true)
    ->param('host', '', new Text(100), 'Supabase Database Host. The host of the project to transfer.', false)
    ->param('database', 'postgres', new Text(100), 'Supabase Database Name. The name of the database to transfer.', true)
    ->param('username', 'postgres', new Text(100), 'Supabase Database Username. The username of the database to transfer.', true)
    ->param('password', '', new Text(100), 'Supabase Database Password. The password of the database to transfer.', false)
    ->param('port', '5432', new Integer(true), 'Supabase Database Port. The port of the database to transfer.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (string $sourceId, string $name, string $url, string $database, string $username, string $password, string $port, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $sourceId = ($sourceId == 'unique()') ? ID::unique() : $sourceId;

        $testAdapter = new Supabase($url, $database, $username, $password, intval($port));
        $testAdapter->check(); // Throws exception on failure

        $source = $dbForProject->createDocument('sources', new Document([
            '$id' => $sourceId,
            '$collection' => ID::custom('sources'),
            'type' => 'supabase',
            'name' => empty($name) ? 'Supabase Project ' . $sourceId : $name,
            'data' => json_encode([
                'url' => $url,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'port' => intval($port),
            ])
        ]));

        $eventsInstance->setParam('sourceId', $source->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($source, Response::MODEL_SOURCE);
    });

App::post('/v1/transfers/sources/supabase/validate')
    ->groups(['api', 'transfers'])
    ->desc('Validate Supabase Source')
    ->label('scope', 'transfers.write')
    ->label('audits.resource', 'sources/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'validateSupabaseSource')
    ->label('sdk.description', '/docs/references/transfers/validate-supabase-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SOURCE_VALIDATION)
    ->param('host', '', new Text(100), 'Supabase Database Host. The host of the project to transfer.', false)
    ->param('database', 'postgres', new Text(100), 'Supabase Database Name. The name of the database to transfer.', true)
    ->param('username', 'postgres', new Text(100), 'Supabase Database Username. The username of the database to transfer.', true)
    ->param('password', '', new Text(100), 'Supabase Database Password. The password of the database to transfer.', false)
    ->param('port', '5432', new Integer(true), 'Supabase Database Port. The port of the database to transfer.', true)
    ->inject('response')
    ->action(function (string $host, string $database, string $username, string $password, string $port, Response $response) {
        $testAdapter = new Supabase($host, $database, $username, $password, $port);

        $result = $testAdapter->check();

        $result = array_filter($result, function ($value) {
            return $value !== [];
        });

        if (count($result) == 0) {
            return $response->dynamic(new Document([
                'success' => true,
                'message' => 'Source is valid',
                'errors' => $result
            ]), Response::MODEL_SOURCE_VALIDATION);
        } else {
            return $response->setStatusCode(401)->dynamic(new Document([
                'success' => false,
                'message' => 'Missing Permissions',
                'errors' => $result
            ]), Response::MODEL_SOURCE_VALIDATION);
        }
    });

App::post('/v1/transfers/sources/nhost')
    ->groups(['api', 'transfers'])
    ->desc('Create Nhost Source')
    ->label('scope', 'transfers.write')
    ->label('event', 'transfers.[sourceId].createNhostSource')
    ->label('audits.event', 'transfers.createNhostSource')
    ->label('audits.resource', 'sources/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'createNhostSource')
    ->label('sdk.description', '/docs/references/transfers/create-nhost-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SOURCE)
    ->param('sourceId', 'unique()', new CustomId(), 'Source unique ID. Use \'unique()\' to auto generate a unique ID for this source.', true)
    ->param('name', '', new Text(256), 'Source Name. Max length: 256 chars.', true)
    ->param('host', '', new Text(100), 'Nhost Database Host. The host of the project to transfer.', false)
    ->param('database', 'postgres', new Text(100), 'Nhost Database Name. The name of the database to transfer.', true)
    ->param('username', 'postgres', new Text(100), 'Nhost Database Username. The username of the database to transfer.', true)
    ->param('password', '', new Text(100), 'Nhost Database Password. The password of the database to transfer.', false)
    ->param('port', '5432', new Integer(true), 'Nhost Database Port. The port of the database to transfer.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (string $sourceId, string $name, string $url, string $database, string $username, string $password, int $port, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $sourceId = ($sourceId == 'unique()') ? ID::unique() : $sourceId;

        $testAdapter = new NHost($url, $database, $username, $password, $port);
        $testAdapter->check(); // Throws exception on failure

        $source = $dbForProject->createDocument('sources', new Document([
            '$id' => $sourceId,
            '$collection' => ID::custom('sources'),
            'type' => 'nhost',
            'name' => empty($name) ? 'Nhost Project ' . $sourceId : $name,
            'data' => json_encode([
                'url' => $url,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'port' => $port,
            ])
        ]));

        $eventsInstance->setParam('sourceId', $source->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($source, Response::MODEL_SOURCE);
    });

App::post('/v1/transfers/sources/nhost/validate')
    ->groups(['api', 'transfers'])
    ->desc('Validate Nhost Source')
    ->label('scope', 'transfers.write')
    ->label('audits.resource', 'sources/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'validateNhostSource')
    ->label('sdk.description', '/docs/references/transfers/validate-nhost-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SOURCE_VALIDATION)
    ->param('host', '', new Text(100), 'Nhost Database Host. The host of the project to validate.', false)
    ->param('database', 'postgres', new Text(100), 'Nhost Database Name. The name of the database to validate.', true)
    ->param('username', 'postgres', new Text(100), 'Nhost Database Username. The username of the database to validate.', true)
    ->param('password', '', new Text(100), 'Nhost Database Password. The password of the database to validate.', false)
    ->param('port', '5432', new Integer(true), 'Nhost Database Port. The port of the database to validate.', true)
    ->inject('response')
    ->action(function (string $url, string $database, string $username, string $password, string $port, Response $response) {
        try {
            $testAdapter = new NHost($url, $database, $username, $password, $port);
        } catch (Throwable $e) {
            return $response->setStatusCode(401)->dynamic(new Document([
                'success' => false,
                'message' => 'Invalid Nhost Source',
                'errors' => [
                    'Databases' => [$e->getMessage()],
                ]
            ]), Response::MODEL_SOURCE_VALIDATION);
        };

        $result = $testAdapter->check();

        $result = array_filter($result, function ($value) {
            return $value !== [];
        });

        if (count($result) == 0) {
            return $response->dynamic(new Document([
                'success' => true,
                'message' => 'Source is valid',
                'errors' => $result
            ]), Response::MODEL_SOURCE_VALIDATION);
        } else {
            return $response->setStatusCode(401)->dynamic(new Document([
                'success' => false,
                'message' => 'Missing Permissions',
                'errors' => $result
            ]), Response::MODEL_SOURCE_VALIDATION);
        }
    });

App::post('/v1/transfers/destinations/appwrite')
    ->groups(['api', 'transfers'])
    ->desc('Create Appwrite Transfer Destination')
    ->label('scope', 'transfers.write')
    ->label('event', 'transfers.[sourceId].createAppwriteDestination')
    ->label('audits.event', 'transfers.createAppwriteDestination')
    ->label('audits.resource', 'sources/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'createAppwriteDestination')
    ->label('sdk.description', '/docs/references/transfers/create-appwrite-source.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DESTINATION)
    ->param('destinationId', 'unique()', new CustomId(), 'Destination unique ID. Use \'unique()\' to auto generate a unique ID for this source.', true)
    ->param('name', '', new Text(256), 'Destination Name. Max length: 256 chars.', true)
    ->param('projectId', '', new UID(), 'Destination Project UID. The UID of the project to transfer.', false)
    ->param('endpoint', '', new URL(), 'Destination Endpoint. The endpoint of the project to transfer.', false)
    ->param('key', '', new Text(1024), 'Destination Key. The key of the project to transfer.', false)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (string $destinationId, string $name, string $projectId, string $endpoint, string $key, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {
        $destinationId = ($destinationId == 'unique()') ? ID::unique() : $destinationId;

        $testAdapter = new Appwrite($projectId, $endpoint, $key);
        $result = $testAdapter->check();

        $result = array_filter($result, function ($value) {
            return $value !== [];
        });

        if (count($result) > 0) {
            throw new Exception('Missing Permissions', 401);
        }

        $destination = $dbForProject->createDocument('destinations', new Document([
            '$id' => $destinationId,
            '$collection' => ID::custom('destinations'),
            'type' => 'appwrite',
            'name' => empty($name) ? 'Appwrite Project ' . $destinationId : $name,
            'data' => json_encode([
                'projectId' => $projectId,
                'endpoint' => $endpoint,
                'key' => $key,
            ])
        ]));

        $eventsInstance->setParam('desinationId', $destination->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($destination, Response::MODEL_DESTINATION);
    });

App::post('/v1/transfers/destinations/appwrite/validate')
    ->groups(['api', 'transfers'])
    ->desc('Validate Appwrite Destination')
    ->label('scope', 'transfers.write')
    ->label('audits.resource', 'sources/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'transfers')
    ->label('sdk.method', 'validateAppwriteDestination')
    ->label('sdk.description', '/docs/references/transfers/validate-appwrite-destination.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DESTINATION_VALIDATION)
    ->param('projectId', '', new UID(), 'Destination Project UID. The UID of the project to transfer.', false)
    ->param('endpoint', '', new URL(), 'Destination Endpoint. The endpoint of the project to transfer.', false)
    ->param('key', '', new Text(1024), 'Destination Key. The key of the project to transfer.', false)
    ->inject('response')
    ->action(function (string $projectId, string $endpoint, string $key, Response $response) {
        $testAdapter = new Appwrite($projectId, $endpoint, $key);

        $result = $testAdapter->check();

        $result = array_filter($result, function ($value) {
            return $value !== [];
        });

        if (count($result) == 0) {
            return $response->dynamic(new Document([
                'success' => true,
                'message' => 'Destination is valid',
                'errors' => $result
            ]), Response::MODEL_DESTINATION_VALIDATION);
        } else {
            return $response->setStatusCode(401)->dynamic(new Document([
                'success' => false,
                'message' => 'Missing Permissions',
                'errors' => $result
            ]), Response::MODEL_DESTINATION_VALIDATION);
        }
    });
