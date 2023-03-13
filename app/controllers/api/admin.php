<?php

use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Utopia\Validator\Text;

App::get('/v1/admin')
    ->desc('List Projects')
    ->groups(['api', 'admin'])
    ->label('scope', 'admin.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'admin')
    ->label('sdk.method', 'listProjects')
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
