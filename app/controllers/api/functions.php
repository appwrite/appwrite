<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Auth;
use Appwrite\Database\Validator\CustomId;
use Utopia\Database\Validator\UID;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Appwrite\Utopia\Response;
use Appwrite\Task\Validator\Cron;
use Utopia\App;
use Utopia\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Config\Config;
use Cron\CronExpression;

include_once __DIR__ . '/../shared/api.php';

App::post('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('Create Function')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/functions/create-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string `unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, and underscore. Can\'t start with a leading underscore. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Function name. Max length: 128 chars.')
    ->param('execute', [], new ArrayList(new Text(64)), 'An array of strings with execution permissions. By default no user is granted with any execute permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('runtime', '', new WhiteList(array_keys(Config::getParam('runtimes')), true), 'Execution runtime.')
    ->param('vars', [], new Assoc(), 'Key-value JSON object.', true)
    ->param('events', [], new ArrayList(new WhiteList(array_keys(Config::getParam('events')), true)), 'Events list.', true)
    ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
    ->param('timeout', 15, new Range(1, 900), 'Function maximum execution time in seconds.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($functionId, $name, $execute, $runtime, $vars, $events, $schedule, $timeout, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $functionId = ($functionId == 'unique()') ? $dbForInternal->getId() : $functionId;
        $function = $dbForInternal->createDocument('functions', new Document([
            '$id' => $functionId,
            'execute' => $execute,
            'dateCreated' => time(),
            'dateUpdated' => time(),
            'status' => 'disabled',
            'name' => $name,
            'runtime' => $runtime,
            'tag' => '',
            'vars' => $vars,
            'events' => $events,
            'schedule' => $schedule,
            'schedulePrevious' => 0,
            'scheduleNext' => 0,
            'timeout' => $timeout,
            'search' => implode(' ', [$functionId, $name, $runtime]),
        ]));

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::get('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('List Functions')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/functions/list-functions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION_LIST)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('after', '', new UID(), 'ID of the function used as the starting point for the query, excluding the function itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($search, $limit, $offset, $after, $orderType, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        if (!empty($after)) {
            $afterFunction = $dbForInternal->getDocument('functions', $after);

            if ($afterFunction->isEmpty()) {
                throw new Exception("Function '{$after}' for the 'after' value not found.", 400);
            }
        }

        $response->dynamic(new Document([
            'functions' => $dbForInternal->find('functions', $queries, $limit, $offset, [], [$orderType], $afterFunction ?? null),
            'sum' => $dbForInternal->count('functions', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_FUNCTION_LIST);
    });

App::get('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Get Function')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/functions/get-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($functionId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $function = $dbForInternal->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::get('/v1/functions/:functionId/usage')
    ->desc('Get Function Usage')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getUsage')
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d']), 'Date range.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForInternal')
    ->inject('register')
    ->action(function ($functionId, $range, $response, $project, $dbForInternal, $register) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Registry\Registry $register */

        $function = $dbForInternal->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }
        
        if(App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $period = [
                '24h' => [
                    'start' => DateTime::createFromFormat('U', \strtotime('-24 hours')),
                    'end' => DateTime::createFromFormat('U', \strtotime('+1 hour')),
                    'group' => '30m',
                ],
                '7d' => [
                    'start' => DateTime::createFromFormat('U', \strtotime('-7 days')),
                    'end' => DateTime::createFromFormat('U', \strtotime('now')),
                    'group' => '1d',
                ],
                '30d' => [
                    'start' => DateTime::createFromFormat('U', \strtotime('-30 days')),
                    'end' => DateTime::createFromFormat('U', \strtotime('now')),
                    'group' => '1d',
                ],
                '90d' => [
                    'start' => DateTime::createFromFormat('U', \strtotime('-90 days')),
                    'end' => DateTime::createFromFormat('U', \strtotime('now')),
                    'group' => '1d',
                ],
            ];
    
            $client = $register->get('influxdb');
    
            $executions = [];
            $failures = [];
            $compute = [];
    
            if ($client) {
                $start = $period[$range]['start']->format(DateTime::RFC3339);
                $end = $period[$range]['end']->format(DateTime::RFC3339);
                $database = $client->selectDB('telegraf');
    
                // Executions
                $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_executions_all" WHERE time > \''.$start.'\' AND time < \''.$end.'\' AND "metric_type"=\'counter\' AND "project"=\''.$project->getId().'\' AND "functionId"=\''.$function->getId().'\' GROUP BY time('.$period[$range]['group'].') FILL(null)');
                $points = $result->getPoints();
    
                foreach ($points as $point) {
                    $executions[] = [
                        'value' => (!empty($point['value'])) ? $point['value'] : 0,
                        'date' => \strtotime($point['time']),
                    ];
                }
    
                // Failures
                $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_executions_all" WHERE time > \''.$start.'\' AND time < \''.$end.'\' AND "metric_type"=\'counter\' AND "project"=\''.$project->getId().'\' AND "functionId"=\''.$function->getId().'\' AND "functionStatus"=\'failed\' GROUP BY time('.$period[$range]['group'].') FILL(null)');
                $points = $result->getPoints();
    
                foreach ($points as $point) {
                    $failures[] = [
                        'value' => (!empty($point['value'])) ? $point['value'] : 0,
                        'date' => \strtotime($point['time']),
                    ];
                }
    
                // Compute
                $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_executions_time" WHERE time > \''.$start.'\' AND time < \''.$end.'\' AND "metric_type"=\'counter\' AND "project"=\''.$project->getId().'\' AND "functionId"=\''.$function->getId().'\' GROUP BY time('.$period[$range]['group'].') FILL(null)');
                $points = $result->getPoints();
    
                foreach ($points as $point) {
                    $compute[] = [
                        'value' => round((!empty($point['value'])) ? $point['value'] / 1000 : 0, 2), // minutes
                        'date' => \strtotime($point['time']),
                    ];
                }
            }
    
            $response->json([
                'range' => $range,
                'executions' => [
                    'data' => $executions,
                    'total' => \array_sum(\array_map(function ($item) {
                        return $item['value'];
                    }, $executions)),
                ],
                'failures' => [
                    'data' => $failures,
                    'total' => \array_sum(\array_map(function ($item) {
                        return $item['value'];
                    }, $failures)),
                ],
                'compute' => [
                    'data' => $compute,
                    'total' => \array_sum(\array_map(function ($item) {
                        return $item['value'];
                    }, $compute)),
                ],
            ]);
        } else {
            $response->json([]);
        }
    });

App::put('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Update Function')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/functions/update-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('name', '', new Text(128), 'Function name. Max length: 128 chars.')
    ->param('execute', [], new ArrayList(new Text(64)), 'An array of strings with execution permissions. By default no user is granted with any execute permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('vars', [], new Assoc(), 'Key-value JSON object.', true)
    ->param('events', [], new ArrayList(new WhiteList(array_keys(Config::getParam('events')), true)), 'Events list.', true)
    ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
    ->param('timeout', 15, new Range(1, 900), 'Function maximum execution time in seconds.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('project')
    ->action(function ($functionId, $name, $execute, $vars, $events, $schedule, $timeout, $response, $dbForInternal, $project) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Document $project */

        $function = $dbForInternal->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $original = $function->getAttribute('schedule', '');
        $cron = (!empty($function->getAttribute('tag', null)) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (!empty($function->getAttribute('tag', null)) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;

        $function = $dbForInternal->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
            'execute' => $execute,
            'dateUpdated' => time(),
            'name' => $name,
            'vars' => $vars,
            'events' => $events,
            'schedule' => $schedule,
            'scheduleNext' => (int)$next,
            'timeout' => $timeout,
            'search' => implode(' ', [$functionId, $name, $function->getAttribute('runtime')]),
        ])));

        if ($next && $schedule !== $original) {
            ResqueScheduler::enqueueAt($next, 'v1-functions', 'FunctionsV1', [
                'projectId' => $project->getId(),
                'webhooks' => $project->getAttribute('webhooks', []),
                'functionId' => $function->getId(),
                'executionId' => null,
                'trigger' => 'schedule',
            ]);  // Async task rescheduale
        }

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::patch('/v1/functions/:functionId/tag')
    ->groups(['api', 'functions'])
    ->desc('Update Function Tag')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.tags.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'updateTag')
    ->label('sdk.description', '/docs/references/functions/update-function-tag.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('tag', '', new UID(), 'Tag unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('project')
    ->action(function ($functionId, $tag, $response, $dbForInternal, $project) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Document $project */

        $function = $dbForInternal->getDocument('functions', $functionId);
        $tag = $dbForInternal->getDocument('tags', $tag);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        if ($tag->isEmpty()) {
            throw new Exception('Tag not found', 404);
        }

        $schedule = $function->getAttribute('schedule', '');
        $cron = (empty($function->getAttribute('tag')) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (empty($function->getAttribute('tag')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;

        $function = $dbForInternal->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
            'tag' => $tag->getId(),
            'scheduleNext' => (int)$next,
        ])));

        if ($next) { // Init first schedule
            ResqueScheduler::enqueueAt($next, 'v1-functions', 'FunctionsV1', [
                'projectId' => $project->getId(),
                'webhooks' => $project->getAttribute('webhooks', []),
                'functionId' => $function->getId(),
                'executionId' => null,
                'trigger' => 'schedule',
            ]);  // Async task rescheduale
        }

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::delete('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Delete Function')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/functions/delete-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('deletes')
    ->action(function ($functionId, $response, $dbForInternal, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $deletes */

        $function = $dbForInternal->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        if (!$dbForInternal->deleteDocument('functions', $function->getId())) {
            throw new Exception('Failed to remove function from DB', 500);
        }

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $function)
        ;

        $response->noContent();
    });

App::post('/v1/functions/:functionId/tags')
    ->groups(['api', 'functions'])
    ->desc('Create Tag')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.tags.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createTag')
    ->label('sdk.description', '/docs/references/functions/create-tag.md')
    ->label('sdk.packaging', true)
    ->label('sdk.request.type', 'multipart/form-data')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TAG)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('command', '', new Text('1028'), 'Code execution command.')
    ->param('code', [], new File(), 'Gzip file with your code package. When used with the Appwrite CLI, pass the path to your code directory, and the CLI will automatically package your code. Use a path that is within the current directory.', false)
    ->inject('request')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($functionId, $command, $file, $request, $response, $dbForInternal, $usage) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $usage */

        $function = $dbForInternal->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $file = $request->getFiles('code');
        $device = Storage::getDevice('functions');
        $fileExt = new FileExt([FileExt::TYPE_GZIP]);
        $fileSize = new FileSize(App::getEnv('_APP_STORAGE_LIMIT', 0));
        $upload = new Upload();

        if (empty($file)) {
            throw new Exception('No file sent', 400);
        }

        // Make sure we handle a single file and multiple files the same way
        $file['name'] = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $file['tmp_name'] = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $file['size'] = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        if (!$fileExt->isValid($file['name'])) { // Check if file type is allowed
            throw new Exception('File type not allowed', 400);
        }

        if (!$fileSize->isValid($file['size'])) { // Check if file size is exceeding allowed limit
            throw new Exception('File size not allowed', 400);
        }

        if (!$upload->isValid($file['tmp_name'])) {
            throw new Exception('Invalid file', 403);
        }

        // Save to storage
        $size = $device->getFileSize($file['tmp_name']);
        $path = $device->getPath(\uniqid().'.'.\pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!$device->upload($file['tmp_name'], $path)) { // TODO deprecate 'upload' and replace with 'move'
            throw new Exception('Failed moving file', 500);
        }
        
        $tagId = $dbForInternal->getId();
        $tag = $dbForInternal->createDocument('tags', new Document([
            '$id' => $tagId,
            '$read' => [],
            '$write' => [],
            'functionId' => $function->getId(),
            'dateCreated' => time(),
            'command' => $command,
            'path' => $path,
            'size' => $size,
            'search' => implode(' ', [$tagId, $command]),
        ]));

        $usage
            ->setParam('storage', $tag->getAttribute('size', 0))
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($tag, Response::MODEL_TAG);
    });

App::get('/v1/functions/:functionId/tags')
    ->groups(['api', 'functions'])
    ->desc('List Tags')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listTags')
    ->label('sdk.description', '/docs/references/functions/list-tags.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TAG_LIST)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('after', '', new UID(), 'ID of the tag used as the starting point for the query, excluding the tag itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($functionId, $search, $limit, $offset, $after, $orderType, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $function = $dbForInternal->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $queries[] = new Query('functionId', Query::TYPE_EQUAL, [$function->getId()]);

        if (!empty($after)) {
            $afterTag = $dbForInternal->getDocument('tags', $after);

            if ($afterTag->isEmpty()) {
                throw new Exception("Tag '{$after}' for the 'after' value not found.", 400);
            }
        }

        $results = $dbForInternal->find('tags', $queries, $limit, $offset, [], [$orderType], $afterTag ?? null);
        $sum = $dbForInternal->count('tags', $queries, APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'tags' => $results,
            'sum' => $sum,
        ]), Response::MODEL_TAG_LIST);
    });

App::get('/v1/functions/:functionId/tags/:tagId')
    ->groups(['api', 'functions'])
    ->desc('Get Tag')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getTag')
    ->label('sdk.description', '/docs/references/functions/get-tag.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TAG)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('tagId', '', new UID(), 'Tag unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($functionId, $tagId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $function = $dbForInternal->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $tag = $dbForInternal->getDocument('tags', $tagId);

        if ($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found', 404);
        }

        if ($tag->isEmpty()) {
            throw new Exception('Tag not found', 404);
        }

        $response->dynamic($tag, Response::MODEL_TAG);
    });

App::delete('/v1/functions/:functionId/tags/:tagId')
    ->groups(['api', 'functions'])
    ->desc('Delete Tag')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.tags.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'deleteTag')
    ->label('sdk.description', '/docs/references/functions/delete-tag.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('tagId', '', new UID(), 'Tag unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($functionId, $tagId, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $usage */

        $function = $dbForInternal->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }
        
        $tag = $dbForInternal->getDocument('tags', $tagId);

        if ($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found', 404);
        }

        if ($tag->isEmpty()) {
            throw new Exception('Tag not found', 404);
        }

        $device = Storage::getDevice('functions');

        if ($device->delete($tag->getAttribute('path', ''))) {
            if (!$dbForInternal->deleteDocument('tags', $tag->getId())) {
                throw new Exception('Failed to remove tag from DB', 500);
            }
        }

        if($function->getAttribute('tag') === $tag->getId()) { // Reset function tag
            $function = $dbForInternal->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
                'tag' => '',
            ])));
        }

        $usage
            ->setParam('storage', $tag->getAttribute('size', 0) * -1)
        ;

        $response->noContent();
    });

App::post('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('Create Execution')
    ->label('scope', 'execution.write')
    ->label('event', 'functions.executions.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createExecution')
    ->label('sdk.description', '/docs/references/functions/create-execution.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION)
    ->label('abuse-limit', 60)
    ->label('abuse-time', 60)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('data', '', new Text(8192), 'String of custom data to send to function.', true)
    // ->param('async', 1, new Range(0, 1), 'Execute code asynchronously. Pass 1 for true, 0 for false. Default value is 1.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForInternal')
    ->inject('user')
    ->action(function ($functionId, $data, /*$async,*/ $response, $project, $dbForInternal, $user) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Document $user */

        Authorization::disable();

        $function = $dbForInternal->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $tag = $dbForInternal->getDocument('tags', $function->getAttribute('tag'));

        if ($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found. Deploy tag before trying to execute a function', 404);
        }

        if ($tag->isEmpty()) {
            throw new Exception('Tag not found. Deploy tag before trying to execute a function', 404);
        }

        Authorization::reset();

        $validator = new Authorization($function, 'execute');

        if (!$validator->isValid($function->getAttribute('execute'))) { // Check if user has write access to execute function
            throw new Exception($validator->getDescription(), 401);
        }

        Authorization::disable();

        $execution = $dbForInternal->createDocument('executions', new Document([
            '$id' => $dbForInternal->getId(),
            '$read' => (!$user->isEmpty()) ? ['user:' . $user->getId()] : [],
            '$write' => [],
            'dateCreated' => time(),
            'functionId' => $function->getId(),
            'tagId' => $tag->getId(),
            'trigger' => 'http', // http / schedule / event
            'status' => 'waiting', // waiting / processing / completed / failed
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => '',
            'time' => 0.0,
        ]));

        Authorization::reset();
        
        $jwt = ''; // initialize
        if (!$user->isEmpty()) { // If userId exists, generate a JWT for function
            
            $sessions = $user->getAttribute('sessions', []);
            $current = new Document();

            foreach ($sessions as $session) { /** @var Utopia\Database\Document $session */
                if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                    $current = $session;
                }
            }

            if(!$current->isEmpty()) {
                $jwtObj = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.
                $jwt = $jwtObj->encode([
                    'userId' => $user->getId(),
                    'sessionId' => $current->getId(),
                ]);
            }
        }

        Resque::enqueue('v1-functions', 'FunctionsV1', [
            'projectId' => $project->getId(),
            'webhooks' => $project->getAttribute('webhooks', []),
            'functionId' => $function->getId(),
            'executionId' => $execution->getId(),
            'trigger' => 'http',
            'data' => $data,
            'userId' => $user->getId(),
            'jwt' => $jwt,
        ]);

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($execution, Response::MODEL_EXECUTION);
    });

App::get('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('List Executions')
    ->label('scope', 'execution.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listExecutions')
    ->label('sdk.description', '/docs/references/functions/list-executions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION_LIST)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('after', '', new UID(), 'ID of the execution used as the starting point for the query, excluding the execution itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($functionId, $limit, $offset, $after, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        Authorization::disable();
        $function = $dbForInternal->getDocument('functions', $functionId);
        Authorization::reset();

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        if (!empty($after)) {
            $afterExecution = $dbForInternal->getDocument('executions', $after);

            if ($afterExecution->isEmpty()) {
                throw new Exception("Execution '{$after}' for the 'after' value not found.", 400);
            }
        }

        $results = $dbForInternal->find('executions', [
            new Query('functionId', Query::TYPE_EQUAL, [$function->getId()]),
        ], $limit, $offset, [], [Database::ORDER_DESC], $afterExecution ?? null);

        $sum = $dbForInternal->count('executions', [
            new Query('functionId', Query::TYPE_EQUAL, [$function->getId()]),
        ], APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'executions' => $results,
            'sum' => $sum,
        ]), Response::MODEL_EXECUTION_LIST);
    });

App::get('/v1/functions/:functionId/executions/:executionId')
    ->groups(['api', 'functions'])
    ->desc('Get Execution')
    ->label('scope', 'execution.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getExecution')
    ->label('sdk.description', '/docs/references/functions/get-execution.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('executionId', '', new UID(), 'Execution unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($functionId, $executionId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        
        Authorization::disable();
        $function = $dbForInternal->getDocument('functions', $functionId);
        Authorization::reset();

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $execution = $dbForInternal->getDocument('executions', $executionId);

        if ($execution->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Execution not found', 404);
        }

        if ($execution->isEmpty()) {
            throw new Exception('Execution not found', 404);
        }

        $response->dynamic($execution, Response::MODEL_EXECUTION);
    });
