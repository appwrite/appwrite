<?php

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Validator\UID;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Appwrite\Utopia\Response;
use Appwrite\Task\Validator\Cron;
use Utopia\App;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Config\Config;
use Cron\CronExpression;
use Utopia\Exception;

include_once __DIR__ . '/../shared/api.php';

App::post('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('Create Function')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/functions/create-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('name', '', new Text(128), 'Function name. Max length: 128 chars.')
    ->param('execute', [], new ArrayList(new Text(64)), 'An array of strings with execution permissions. By default no user is granted with any execute permissions. [learn more about permissions](/docs/permissions) and get a full list of available permissions.')
    ->param('env', '', new WhiteList(array_keys(Config::getParam('environments')), true), 'Execution enviornment.')
    ->param('vars', [], new Assoc(), 'Key-value JSON object.', true)
    ->param('events', [], new ArrayList(new WhiteList(array_keys(Config::getParam('events')), true)), 'Events list.', true)
    ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
    ->param('timeout', 15, new Range(1, 900), 'Function maximum execution time in seconds.', true)
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($name, $execute, $env, $vars, $events, $schedule, $timeout, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $function = $projectDB->createDocument([
            '$collection' => Database::SYSTEM_COLLECTION_FUNCTIONS,
            '$permissions' => [
                'execute' => $execute,
            ],
            'dateCreated' => time(),
            'dateUpdated' => time(),
            'status' => 'disabled',
            'name' => $name,
            'env' => $env,
            'tag' => '',
            'vars' => $vars,
            'events' => $events,
            'schedule' => $schedule,
            'schedulePrevious' => null,
            'scheduleNext' => null,
            'timeout' => $timeout,
        ]);

        if (false === $function) {
            throw new Exception('Failed saving function to DB', 500);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($function, Response::MODEL_FUNCTION)
        ;
    });

App::get('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('List Functions')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/functions/list-functions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION_LIST)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($search, $limit, $offset, $orderType, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $results = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderType' => $orderType,
            'search' => $search,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_FUNCTIONS,
            ],
        ]);

        $response->dynamic(new Document([
            'sum' => $projectDB->getSum(),
            'functions' => $results
        ]), Response::MODEL_FUNCTION_LIST);
    });

App::get('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Get Function')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/functions/get-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($functionId, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::get('/v1/functions/:functionId/usage')
    ->desc('Get Function Usage')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_CONSOLE])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getUsage')
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d']), 'Date range.', true)
    ->inject('response')
    ->inject('project')
    ->inject('projectDB')
    ->inject('register')
    ->action(function ($functionId, $range, $response, $project, $projectDB, $register) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Database $consoleDB */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Utopia\Registry\Registry $register */

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
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
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
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
    ->inject('projectDB')
    ->inject('project')
    ->action(function ($functionId, $name, $execute, $vars, $events, $schedule, $timeout, $response, $projectDB, $project) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Database\Document $project */

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $original = $function->getAttribute('schedule', '');
        $cron = (!empty($function->getAttribute('tag', null)) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (!empty($function->getAttribute('tag', null)) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : null;

        $function = $projectDB->updateDocument(array_merge($function->getArrayCopy(), [
            '$permissions' => [
                'execute' => $execute,
            ],
            'dateUpdated' => time(),
            'name' => $name,
            'vars' => $vars,
            'events' => $events,
            'schedule' => $schedule,
            'scheduleNext' => $next,
            'timeout' => $timeout,
        ]));

        if (false === $function) {
            throw new Exception('Failed saving function to DB', 500);
        }

        if ($next && $schedule !== $original) {
            ResqueScheduler::enqueueAt($next, 'v1-functions', 'FunctionsV1', [
                'projectId' => $project->getId(),
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
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'updateTag')
    ->label('sdk.description', '/docs/references/functions/update-function-tag.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('tag', '', new UID(), 'Tag unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('project')
    ->action(function ($functionId, $tag, $response, $projectDB, $project) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Database\Document $project */

        $function = $projectDB->getDocument($functionId);
        $tag = $projectDB->getDocument($tag);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
            throw new Exception('Tag not found', 404);
        }

        $schedule = $function->getAttribute('schedule', '');
        $cron = (empty($function->getAttribute('tag')) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (empty($function->getAttribute('tag')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : null;

        $function = $projectDB->updateDocument(array_merge($function->getArrayCopy(), [
            'tag' => $tag->getId(),
            'scheduleNext' => $next,
        ]));

        if ($next) { // Init first schedule
            ResqueScheduler::enqueueAt($next, 'v1-functions', 'FunctionsV1', [
                'projectId' => $project->getId(),
                'functionId' => $function->getId(),
                'executionId' => null,
                'trigger' => 'schedule',
            ]);  // Async task rescheduale
        }

        if (false === $function) {
            throw new Exception('Failed saving function to DB', 500);
        }

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::delete('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Delete Function')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/functions/delete-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('deletes')
    ->action(function ($functionId, $response, $projectDB, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $deletes */

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        if (!$projectDB->deleteDocument($function->getId())) {
            throw new Exception('Failed to remove function from DB', 500);
        }

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $function->getArrayCopy())
        ;

        $tags = $projectDB->getCollection([
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_TAGS,
                'functionId='.$function->getId(),
            ],
        ]);

        foreach($tags as &$tag) {
            Resque::enqueue('v1-functions', 'FunctionsV1', [
                'functionId' => $function->getId(),
                'tagId' => $tag->getId(),
                'trigger' => 'delete',
            ]);
        }

        $response->noContent();
    });

App::post('/v1/functions/:functionId/tags')
    ->groups(['api', 'functions'])
    ->desc('Create Tag')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
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
    ->inject('projectDB')
    ->inject('usage')
    ->action(function ($functionId, $command, $file, $request, $response, $projectDB, $usage) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $usage */

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
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
        
        $tag = $projectDB->createDocument([
            '$collection' => Database::SYSTEM_COLLECTION_TAGS,
            '$permissions' => [
                'read' => [],
                'write' => [],
            ],
            'functionId' => $function->getId(),
            'dateCreated' => time(),
            'command' => $command,
            'path' => $path,
            'size' => $size,
        ]);

        if (false === $tag) {
            throw new Exception('Failed saving tag to DB', 500);
        }

        $usage
            ->setParam('storage', $tag->getAttribute('size', 0))
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($tag, Response::MODEL_TAG)
        ;
    });

App::get('/v1/functions/:functionId/tags')
    ->groups(['api', 'functions'])
    ->desc('List Tags')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
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
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($functionId, $search, $limit, $offset, $orderType, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }
        
        $results = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderType' => $orderType,
            'search' => $search,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_TAGS,
                'functionId='.$function->getId(),
            ],
        ]);

        $response->dynamic(new Document([
            'sum' => $projectDB->getSum(),
            'tags' => $results
        ]), Response::MODEL_TAG_LIST);
    });

App::get('/v1/functions/:functionId/tags/:tagId')
    ->groups(['api', 'functions'])
    ->desc('Get Tag')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getTag')
    ->label('sdk.description', '/docs/references/functions/get-tag.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TAG)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('tagId', '', new UID(), 'Tag unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($functionId, $tagId, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $tag = $projectDB->getDocument($tagId);

        if ($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found', 404);
        }

        if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
            throw new Exception('Tag not found', 404);
        }

        $response->dynamic($tag, Response::MODEL_TAG);
    });

App::delete('/v1/functions/:functionId/tags/:tagId')
    ->groups(['api', 'functions'])
    ->desc('Delete Tag')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'deleteTag')
    ->label('sdk.description', '/docs/references/functions/delete-tag.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('tagId', '', new UID(), 'Tag unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('usage')
    ->action(function ($functionId, $tagId, $response, $projectDB, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $usage */

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }
        
        $tag = $projectDB->getDocument($tagId);

        if ($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found', 404);
        }

        if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
            throw new Exception('Tag not found', 404);
        }

        $device = Storage::getDevice('functions');

        if ($device->delete($tag->getAttribute('path', ''))) {
            if (!$projectDB->deleteDocument($tag->getId())) {
                throw new Exception('Failed to remove tag from DB', 500);
            }
        }

        if($function->getAttribute('tag') === $tag->getId()) { // Reset function tag
            $function = $projectDB->updateDocument(array_merge($function->getArrayCopy(), [
                'tag' => '',
            ]));
    
            if (false === $function) {
                throw new Exception('Failed saving function to DB', 500);
            }
        }

        Resque::enqueue('v1-functions', 'FunctionsV1', [
            'functionId' => $function->getId(),
            'tagId' => $tag->getId(),
            'trigger' => 'delete',
        ]);

        $usage
            ->setParam('storage', $tag->getAttribute('size', 0) * -1)
        ;

        $response->noContent();
    });

App::post('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('Create Execution')
    ->label('scope', 'execution.write')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createExecution')
    ->label('sdk.description', '/docs/references/functions/create-execution.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION)
    ->label('abuse-limit', 60)
    ->label('abuse-time', 60)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    // ->param('async', 1, new Range(0, 1), 'Execute code asynchronously. Pass 1 for true, 0 for false. Default value is 1.', true)
    ->inject('response')
    ->inject('project')
    ->inject('projectDB')
    ->action(function ($functionId, /*$async,*/ $response, $project, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Database $projectDB */

        Authorization::disable();

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $tag = $projectDB->getDocument($function->getAttribute('tag'));

        if ($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found. Deploy tag before trying to execute a function', 404);
        }

        if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
            throw new Exception('Tag not found. Deploy tag before trying to execute a function', 404);
        }

        Authorization::reset();

        $validator = new Authorization($function, 'execute');

        if (!$validator->isValid($function->getPermissions())) { // Check if user has write access to execute function
            throw new Exception($validator->getDescription(), 401);
        }

        Authorization::disable();

        $execution = $projectDB->createDocument([
            '$collection' => Database::SYSTEM_COLLECTION_EXECUTIONS,
            '$permissions' => [
                'read' => $function->getPermissions()['execute'] ?? [],
                'write' => [],
            ],
            'dateCreated' => time(),
            'functionId' => $function->getId(),
            'trigger' => 'http', // http / schedule / event
            'status' => 'waiting', // waiting / processing / completed / failed
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => '',
            'time' => 0,
        ]);

        Authorization::reset();

        if (false === $execution) {
            throw new Exception('Failed saving execution to DB', 500);
        }
    
        Resque::enqueue('v1-functions', 'FunctionsV1', [
            'projectId' => $project->getId(),
            'functionId' => $function->getId(),
            'executionId' => $execution->getId(),
            'trigger' => 'http',
        ]);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($execution, Response::MODEL_EXECUTION)
        ;
    });

App::get('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('List Executions')
    ->label('scope', 'execution.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listExecutions')
    ->label('sdk.description', '/docs/references/functions/list-executions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION_LIST)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($functionId, $search, $limit, $offset, $orderType, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        
        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }
        
        $results = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderType' => $orderType,
            'search' => $search,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_EXECUTIONS,
                'functionId='.$function->getId(),
            ],
        ]);

        $response->dynamic(new Document([
            'sum' => $projectDB->getSum(),
            'executions' => $results
        ]), Response::MODEL_EXECUTION_LIST);
    });

App::get('/v1/functions/:functionId/executions/:executionId')
    ->groups(['api', 'functions'])
    ->desc('Get Execution')
    ->label('scope', 'execution.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getExecution')
    ->label('sdk.description', '/docs/references/functions/get-execution.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION)
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('executionId', '', new UID(), 'Execution unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($functionId, $executionId, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        
        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $execution = $projectDB->getDocument($executionId);

        if ($execution->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Execution not found', 404);
        }

        if (empty($execution->getId()) || Database::SYSTEM_COLLECTION_EXECUTIONS != $execution->getCollection()) {
            throw new Exception('Execution not found', 404);
        }

        $response->dynamic($execution, Response::MODEL_EXECUTION);
    });
