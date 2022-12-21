<?php

use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Validator\Event;
use Appwrite\Network\Validator\CNAME;
use Appwrite\Network\Validator\Domain as DomainValidator;
use Appwrite\Network\Validator\Origin;
use Appwrite\Network\Validator\URL;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\ID;
use Utopia\Database\DateTime;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Domain;
use Utopia\Registry\Registry;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Hostname;
use Utopia\Validator\Integer;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::get('/v1/projects')
    ->desc('List Projects')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'list')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT_LIST)
    ->param('queries', [], new Projects(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Projects::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (array $queries, string $search, Response $response, Database $dbForConsole) {

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $projectId = $cursor->getValue();
            $cursorDocument = $dbForConsole->getDocument('projects', $projectId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Project '{$projectId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'projects' => $dbForConsole->find('projects', $queries),
            'total' => $dbForConsole->count('projects', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_PROJECT_LIST);
    });

    App::get('/v1/projects/:projectId/webhooks')
    ->desc('List Webhooks')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listWebhooks')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_WEBHOOK_LIST)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhooks = $dbForConsole->find('webhooks', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'webhooks' => $webhooks,
            'total' => count($webhooks),
        ]), Response::MODEL_WEBHOOK_LIST);
    });

    App::get('/v1/projects/:projectId/keys')
    ->desc('List Keys')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listKeys')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_KEY_LIST)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $keys = $dbForConsole->find('keys', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'keys' => $keys,
            'total' => count($keys),
        ]), Response::MODEL_KEY_LIST);
    });

    App::get('/v1/projects/:projectId/domains')
    ->desc('List Domains')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listDomains')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOMAIN_LIST)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $domains = $dbForConsole->find('domains', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'domains' => $domains,
            'total' => count($domains),
        ]), Response::MODEL_DOMAIN_LIST);
    });

    App::get('/v1/projects/:projectId/platforms')
    ->desc('List Platforms')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listPlatforms')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PLATFORM_LIST)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platforms = $dbForConsole->find('platforms', [
            Query::equal('projectId', [$project->getId()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'platforms' => $platforms,
            'total' => count($platforms),
        ]), Response::MODEL_PLATFORM_LIST);
    });
