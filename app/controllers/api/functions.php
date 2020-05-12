<?php

global $utopia, $response, $projectDB;

use Appwrite\Database\Database;
use Appwrite\Database\Validator\UID;
use Appwrite\Task\Validator\Cron;
use Utopia\Response;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Cron\CronExpression;

include_once __DIR__ . '/../shared/api.php';

$utopia->post('/v1/functions')
    ->desc('Create Function')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/functions/create-function.md')
    ->param('name', '', function () { return new Text(128); }, 'Function name.')
    ->param('vars', [], function () { return new Assoc();}, 'Key-value JSON object.', true)
    ->param('events', [], function () { return new ArrayList(new Text(256)); }, 'Events list.', true)
    ->param('schedule', '', function () { return new Cron(); }, 'Schedule CRON syntax.', true)
    ->param('timeout', 15, function () { return new Range(0, 60); }, 'Function maximum execution time in seconds.', true)
    ->action(
        function ($name, $vars, $events, $schedule, $timeout) use ($response, $projectDB) {
            $function = $projectDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_FUNCTIONS,
                '$permissions' => [
                    'read' => [],
                    'write' => [],
                ],
                'dateCreated' => time(),
                'dateUpdated' => time(),
                'status' => 'paused',
                'name' => $name,
                'tag' => '',
                'vars' => $vars,
                'events' => $events,
                'schedule' => $schedule,
                'previous' => null,
                'next' => null,
                'timeout' => $timeout,
            ]);

            if (false === $function) {
                throw new Exception('Failed saving function to DB', 500);
            }

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($function->getArrayCopy())
            ;
        }
    );

$utopia->get('/v1/functions')
    ->desc('List Functions')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/functions/list-functions.md')
    ->param('search', '', function () { return new Text(256); }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () { return new Range(0, 100); }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () { return new Range(0, 2000); }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(['ASC', 'DESC']); }, 'Order result by ASC or DESC order.', true)
    ->action(
        function ($search, $limit, $offset, $orderType) use ($response, $projectDB) {
            $results = $projectDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => 'dateCreated',
                'orderType' => $orderType,
                'orderCast' => 'int',
                'search' => $search,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_FUNCTIONS,
                ],
            ]);

            $response->json(['sum' => $projectDB->getSum(), 'functions' => $results]);
        }
    );

$utopia->get('/v1/functions/:functionId')
    ->desc('Get Function')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/functions/get-function.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->action(
        function ($functionId) use ($response, $projectDB) {
            $function = $projectDB->getDocument($functionId);

            if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('function not found', 404);
            }

            $response->json($function->getArrayCopy());
        }
    );

$utopia->put('/v1/functions/:functionId')
    ->desc('Update Function')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/functions/update-function.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('name', '', function () { return new Text(128); }, 'Function name.')
    ->param('vars', [], function () { return new Assoc();}, 'Key-value JSON object.', true)
    ->param('events', [], function () { return new ArrayList(new Text(256)); }, 'Events list.', true)
    ->param('schedule', '', function () { return new Cron(); }, 'Schedule CRON syntax.', true)
    ->param('timeout', 15, function () { return new Range(0, 60); }, 'Function maximum execution time in seconds.', true)
    ->action(
        function ($functionId, $name, $vars, $events, $schedule, $timeout) use ($response, $projectDB) {
            $function = $projectDB->getDocument($functionId);

            if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('Function not found', 404);
            }

            $cron = (!empty($function->getAttribute('tag', null)) && !empty($schedule)) ? CronExpression::factory($schedule) : null;
            $next = (!empty($function->getAttribute('tag', null)) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : null;

            $function = $projectDB->updateDocument(array_merge($function->getArrayCopy(), [
                'dateUpdated' => time(),
                'name' => $name,
                'vars' => $vars,
                'events' => $events,
                'schedule' => $schedule,
                'previous' => null,
                'next' => $next,
                'timeout' => $timeout,   
            ]));

            if (false === $function) {
                throw new Exception('Failed saving function to DB', 500);
            }

            $response->json($function->getArrayCopy());
        }
    );

$utopia->patch('/v1/functions/:functionId/tag')
    ->desc('Update Function Tag')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'updateTag')
    ->label('sdk.description', '/docs/references/functions/update-tag.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('tag', '', function () { return new UID(); }, 'Tag unique ID.')
    ->action(
        function ($functionId, $tag) use ($response, $projectDB) {
            $function = $projectDB->getDocument($functionId);

            if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('Function not found', 404);
            }

            $schedule = $function->getAttribute('schedule', '');
            $cron = (!empty($function->getAttribute('tag')&& !empty($schedule))) ? CronExpression::factory($schedule) : null;
            $next = (!empty($function->getAttribute('tag')&& !empty($schedule))) ? $cron->getNextRunDate()->format('U') : null;

            $function = $projectDB->updateDocument(array_merge($function->getArrayCopy(), [
                'tag' => $tag,
                'next' => $next,
            ]));

            if (false === $function) {
                throw new Exception('Failed saving function to DB', 500);
            }

            $response->json($function->getArrayCopy());
        }
    );

$utopia->delete('/v1/functions/:functionId')
    ->desc('Delete Function')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/functions/delete-function.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->action(
        function ($functionId) use ($response, $projectDB, $webhook, $audit, $usage) {
            $function = $projectDB->getDocument($functionId);

            if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('Function not found', 404);
            }

            if (!$projectDB->deleteDocument($function->getId())) {
                throw new Exception('Failed to remove function from DB', 500);
            }

            $response->noContent();
        }
    );

$utopia->post('/v1/functions/:functionId/tags')
    ->desc('Create Tag')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createTag')
    ->label('sdk.description', '/docs/references/functions/create-tag.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('env', '', function () { return new WhiteList(['node-14', 'node-12', 'php-7.4']); }, 'Execution enviornment.')
    ->param('command', '', function () { return new Text('1028'); }, 'Code execution command.')
    ->param('code', '', function () { return new Text(128); }, 'Code package. Use the '.APP_NAME.' code packager to create a deployable package file.')
    ->action(
        function ($functionId, $env, $command, $code) use ($response, $projectDB) {
            $function = $projectDB->getDocument($functionId);

            if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('Function not found', 404);
            }
            
            $tag = $projectDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_TAGS,
                '$permissions' => [
                    'read' => [],
                    'write' => [],
                ],
                'dateCreated' => time(),
                'functionId' => $function->getId(),
                'env' => $env,
                'command' => $command,
                'code' => $code,
            ]);

            if (false === $tag) {
                throw new Exception('Failed saving tag to DB', 500);
            }

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($tag->getArrayCopy())
            ;
        }
    );

$utopia->get('/v1/functions/:functionId/tags')
    ->desc('List Tags')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listTags')
    ->label('sdk.description', '/docs/references/functions/list-tags.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('search', '', function () { return new Text(256); }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () { return new Range(0, 100); }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () { return new Range(0, 2000); }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(['ASC', 'DESC']); }, 'Order result by ASC or DESC order.', true)
    ->action(
        function ($functionId, $search, $limit, $offset, $orderType) use ($response, $projectDB) {
            $function = $projectDB->getDocument($functionId);

            if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('Function not found', 404);
            }
            
            $results = $projectDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => 'dateCreated',
                'orderType' => $orderType,
                'orderCast' => 'int',
                'search' => $search,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_TAGS,
                    'functionId='.$function->getId(),
                ],
            ]);

            $response->json(['sum' => $projectDB->getSum(), 'tags' => $results]);
        }
    );

$utopia->get('/v1/functions/:functionId/tags/:tagId')
    ->desc('Get Tag')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getTag')
    ->label('sdk.description', '/docs/references/functions/get-tag.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('tagId', '', function () { return new UID(); }, 'Tag unique ID.')
    ->action(
        function ($functionId, $tagId) use ($response, $projectDB) {
            $function = $projectDB->getDocument($functionId);

            if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('Function not found', 404);
            }

            $tag = $projectDB->getDocument($tagId);

            if($tag->getAttribute('functionId') !== $function->getId()) {
                throw new Exception('Tag not found', 404);
            }

            if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
                throw new Exception('Tag not found', 404);
            }

            $response->json($tag->getArrayCopy());
        }
    );

$utopia->delete('/v1/functions/:functionId/tags/:tagId')
    ->desc('Delete Tag')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'deleteTag')
    ->label('sdk.description', '/docs/references/functions/delete-tag.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('tagId', '', function () { return new UID(); }, 'Tag unique ID.')
    ->action(
        function ($functionId, $tagId) use ($response, $projectDB) {
            $function = $projectDB->getDocument($functionId);

            if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('Function not found', 404);
            }
            
            $tag = $projectDB->getDocument($tagId);

            if($tag->getAttribute('functionId') !== $function->getId()) {
                throw new Exception('Tag not found', 404);
            }

            if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
                throw new Exception('Tag not found', 404);
            }

            if (!$projectDB->deleteDocument($tag->getId())) {
                throw new Exception('Failed to remove tag from DB', 500);
            }

            $response->noContent();
        }
    );

$utopia->post('/v1/functions/:functionId/executions')
    ->desc('Create Execution')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createExecution')
    ->label('sdk.description', '/docs/references/functions/create-execution.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('async', 1, function () { return new Range(0, 1); }, 'Execute code asynchronously. Pass 1 for true, 0 for false. Default value is 1.', true)
    ->action(
        function ($functionId, $async) use ($response, $projectDB) {
            $function = $projectDB->getDocument($functionId);

            if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('Function not found', 404);
            }
            
            $execution = $projectDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_EXECUTIONS,
                '$permissions' => [
                    'read' => [],
                    'write' => [],
                ],
                'dateCreated' => time(),
                'functionId' => $function->getId(),
                'status' => 'waiting', // Proccesing / Completed / Failed
                'exitCode' => 0,
                'stdout' => '',
                'stderr' => '',
                'time' => 0,
            ]);

            if (false === $execution) {
                throw new Exception('Failed saving execution to DB', 500);
            }
            
            $tag = $projectDB->getDocument($function->getAttribute('tag'));

            if($tag->getAttribute('functionId') !== $function->getId()) {
                throw new Exception('Tag not found. Deploy tag before trying to execute a function', 404);
            }

            if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
                throw new Exception('Tag not found. Deploy tag before trying to execute a function', 404);
            }

            if((bool)$async) {

            }

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($execution->getArrayCopy())
            ;
        }
    );

$utopia->get('/v1/functions/:functionId/executions')
    ->desc('List Executions')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listExecutions')
    ->label('sdk.description', '/docs/references/functions/list-executions.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('search', '', function () { return new Text(256); }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () { return new Range(0, 100); }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () { return new Range(0, 2000); }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(['ASC', 'DESC']); }, 'Order result by ASC or DESC order.', true)
    ->action(
        function ($functionId, $search, $limit, $offset, $orderType) use ($response, $projectDB) {
            $function = $projectDB->getDocument($functionId);

            if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('Function not found', 404);
            }
            
            $results = $projectDB->getCollection([
                'limit' => $limit,
                'offset' => $offset,
                'orderField' => 'dateCreated',
                'orderType' => $orderType,
                'orderCast' => 'int',
                'search' => $search,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_EXECUTIONS,
                    'functionId='.$function->getId(),
                ],
            ]);

            $response->json(['sum' => $projectDB->getSum(), 'executions' => $results]);
        }
    );

$utopia->get('/v1/functions/:functionId/executions/:executionId')
    ->desc('Get Execution')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getExecution')
    ->label('sdk.description', '/docs/references/functions/get-execution.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('executionId', '', function () { return new UID(); }, 'Execution unique ID.')
    ->action(
        function ($functionId, $executionId) use ($response, $projectDB) {
            $function = $projectDB->getDocument($functionId);

            if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('Function not found', 404);
            }

            $execution = $projectDB->getDocument($executionId);

            if($execution->getAttribute('functionId') !== $function->getId()) {
                throw new Exception('Execution not found', 404);
            }

            if (empty($execution->getId()) || Database::SYSTEM_COLLECTION_EXECUTIONS != $execution->getCollection()) {
                throw new Exception('Execution not found', 404);
            }

            $response->json($execution->getArrayCopy());
        }
    );