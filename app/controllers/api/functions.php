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
use Utopia\CLI\Console;
use Utopia\Validator\Boolean;

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
    ->param('functionId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string `unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
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
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('cursor', '', new UID(), 'ID of the function used as the starting point for the query, excluding the function itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($search, $limit, $offset, $cursor, $cursorDirection, $orderType, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        if (!empty($cursor)) {
            $cursorFunction = $dbForInternal->getDocument('functions', $cursor);

            if ($cursorFunction->isEmpty()) {
                throw new Exception("Function '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $response->dynamic(new Document([
            'functions' => $dbForInternal->find('functions', $queries, $limit, $offset, [], [$orderType], $cursorFunction ?? null, $cursorDirection),
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
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_FUNCTIONS)
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
        
        $usage = [];
        if(App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '30m',
                    'limit' => 48,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];
            
            $metrics = [
                "functions.$functionId.executions", 
                "functions.$functionId.failures", 
                "functions.$functionId.compute"
            ];

            $stats = [];

            Authorization::skip(function() use ($dbForInternal, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForInternal->find('stats', [
                        new Query('period', Query::TYPE_EQUAL, [$period]),
                        new Query('metric', Query::TYPE_EQUAL, [$metric]),
                    ], $limit, 0, ['time'], [Database::ORDER_DESC]);
    
                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match($period) { // convert period to seconds for unix timestamp math
                            '30m' => 1800,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => ($stats[$metric][$last]['date'] ?? \time()) - $diff, // time of last metric minus period
                        ];
                        $backfill--;
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }    
            });

            $usage = new Document([
                'range' => $range,
                'functionsExecutions' => $stats["functions.$functionId.executions"],
                'functionsFailures' => $stats["functions.$functionId.failures"],
                'functionsCompute' => $stats["functions.$functionId.compute"]
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_FUNCTIONS);
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
    ->inject('user')
    ->action(function ($functionId, $name, $execute, $vars, $events, $schedule, $timeout, $response, $dbForInternal, $project, $user) {
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
                'userId' => $user->getId(),
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
    ->inject('user')
    ->action(function ($functionId, $tag, $response, $dbForInternal, $project, $user) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Document $user */

        $function = $dbForInternal->getDocument('functions', $functionId);

        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, "http://appwrite-executor:8080/v1/tag");
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'functionId' => $functionId,
            'tagId' => $tag,
            'userId' => $user->getId()
        ]));
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-appwrite-project: '.$project->getId(),
            'x-appwrite-executor-key: '. App::getEnv('_APP_EXECUTOR_SECRET', '')
        ]);

        $executorResponse = \curl_exec($ch);

        $error = \curl_error($ch);

        if (!empty($error)) {
            throw new Exception('Curl error: ' . $error, 500);
        }

        // Check status code
        $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (200 !== $statusCode) {
            throw new Exception('Executor error: ' . $executorResponse, $statusCode);
        }

        \curl_close($ch);

        $response->dynamic(new Document(json_decode($executorResponse, true)), Response::MODEL_FUNCTION);
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
    ->inject('project')
    ->action(function ($functionId, $response, $dbForInternal, $deletes, $project) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $deletes */

        $function = $dbForInternal->getDocument('functions', $functionId);

        // Request executor to delete tag containers
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, "http://appwrite-executor:8080/v1/cleanup/function");
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'functionId' => $functionId
        ]));
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-appwrite-project: '.$project->getId(),
            'x-appwrite-executor-key: '. App::getEnv('_APP_EXECUTOR_SECRET', '')
        ]);

        $executorResponse = \curl_exec($ch);

        $error = \curl_error($ch);

        if (!empty($error)) {
            throw new Exception('Curl error: ' . $error, 500);
        }

        // Check status code
        $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (200 !== $statusCode) {
            throw new Exception('Executor error: ' . $executorResponse, $statusCode);
        }

        \curl_close($ch);

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
    ->param('entrypoint', '', new Text('1028'), 'Entrypoint File.')
    ->param('code', [], new File(), 'Gzip file with your code package. When used with the Appwrite CLI, pass the path to your code directory, and the CLI will automatically package your code. Use a path that is within the current directory.', false)
    ->inject('request')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($functionId, $entrypoint, $file, $request, $response, $dbForInternal, $usage) {
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
            'entrypoint' => $entrypoint,
            'path' => $path,
            'size' => $size,
            'search' => implode(' ', [$tagId, $entrypoint]),
            'status' => 'pending',
            'buildPath' => '',
            'buildStdout' => '',
            'buildStderr' => ''
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
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('cursor', '', new UID(), 'ID of the tag used as the starting point for the query, excluding the tag itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($functionId, $search, $limit, $offset, $cursor, $cursorDirection, $orderType, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $function = $dbForInternal->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        if (!empty($cursor)) {
            $cursorTag = $dbForInternal->getDocument('tags', $cursor);

            if ($cursorTag->isEmpty()) {
                throw new Exception("Tag '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $queries[] = new Query('functionId', Query::TYPE_EQUAL, [$function->getId()]);

        $results = $dbForInternal->find('tags', $queries, $limit, $offset, [], [$orderType], $cursorTag ?? null, $cursorDirection);
        $sum = $dbForInternal->count('tags', $queries, APP_LIMIT_COUNT);

        // Get Current Build Data
        foreach ($results as &$tag) {
            $build = $dbForInternal->getDocument('builds', $tag->getAttribute('buildId', ''));

            $tag['status'] = $build->getAttribute('status', 'pending');
            $tag['buildStdout'] = $build->getAttribute('stdout', '');
            $tag['buildStderr'] = $build->getAttribute('stderr', '');
        }

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
    ->inject('project')
    ->action(function ($functionId, $tagId, $response, $dbForInternal, $usage, $project) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $usage */
        /** @var Utopia\Database\Document $project */

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

        // Request executor to delete tag containers
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, "http://appwrite-executor:8080/v1/cleanup/tag");
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'tagId' => $tagId
        ]));
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-appwrite-project: '.$project->getId(),
            'x-appwrite-executor-key: '. App::getEnv('_APP_EXECUTOR_SECRET', '')
        ]);

        $executorResponse = \curl_exec($ch);

        $error = \curl_error($ch);

        if (!empty($error)) {
            throw new Exception('Curl error: ' . $error, 500);
        }

        // Check status code
        $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (200 !== $statusCode) {
            throw new Exception('Executor error: ' . $executorResponse, $statusCode);
        }

        \curl_close($ch);

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
    ->param('async', true, new Boolean(), 'Execute code asynchronously. Default value is true.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForInternal')
    ->inject('user')
    ->action(function ($functionId, $data, $async, $response, $project, $dbForInternal, $user) {
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

        $executionId = $dbForInternal->getId();

        $execution = $dbForInternal->createDocument('executions', new Document([
            '$id' => $executionId,
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
            'search' => implode(' ', [$functionId, $executionId]),
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

        if ($async) {
            Resque::enqueue('v1-functions', 'FunctionsV1', [
                'projectId' => $project->getId(),
                'webhooks' => $project->getAttribute('webhooks', []),
                'functionId' => $function->getId(),
                'executionId' => $execution->getId(),
                'trigger' => 'http',
                'data' => $data,
                'userId' => $user->getId(),
                'jwt' => $jwt
            ]);

            $response->setStatusCode(Response::STATUS_CODE_CREATED);
            $response->dynamic($execution, Response::MODEL_EXECUTION);
            return $response;
        }
            // Directly execute function.
            $ch = \curl_init();
            \curl_setopt($ch, CURLOPT_URL, "http://appwrite-executor:8080/v1/execute");
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'trigger' => 'http',
                'projectId' => $project->getId(),
                'executionId' => $execution->getId(),
                'functionId' => $function->getId(),
                'data' => $data,
                'webhooks' => $project->getAttribute('webhooks', []),
                'userId' => $user->getId(),
                'jwt' => $jwt,
            ]));
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900) + 200); // + 200 for safety margin
            \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'x-appwrite-project: '.$project->getId(),
                'x-appwrite-executor-key: '. App::getEnv('_APP_EXECUTOR_SECRET', '')
            ]);
    
            $responseExecute = \curl_exec($ch);
    
            $error = \curl_error($ch);
            if (!empty($error)) {
                Console::error('Curl error: '.$error);
            }
    
            \curl_close($ch);

            $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document(json_decode($responseExecute, true)), Response::MODEL_SYNC_EXECUTION);
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
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('cursor', '', new UID(), 'ID of the execution used as the starting point for the query, excluding the execution itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($functionId, $limit, $offset, $search, $cursor, $cursorDirection, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $function = Authorization::skip(function() use ($dbForInternal, $functionId) {
            return $dbForInternal->getDocument('functions', $functionId);
        });

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        if (!empty($cursor)) {
            $cursorExecution = $dbForInternal->getDocument('executions', $cursor);

            if ($cursorExecution->isEmpty()) {
                throw new Exception("Execution '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        $queries = [
            new Query('functionId', Query::TYPE_EQUAL, [$function->getId()])
        ];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $results = $dbForInternal->find('executions', $queries, $limit, $offset, [], [Database::ORDER_DESC], $cursorExecution ?? null, $cursorDirection);
        $sum = $dbForInternal->count('executions', $queries, APP_LIMIT_COUNT);

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

App::get('/v1/builds')
    ->groups(['api', 'functions'])
    ->desc('Get Builds')
    ->label('scope', 'execution.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listBuilds')
    ->label('sdk.description', '/docs/references/functions/list-builds.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BUILD_LIST)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('cursor', '', new UID(), 'ID of the build used as the starting point for the query, excluding the build itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($limit, $offset, $search, $cursor, $cursorDirection, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        if (!empty($cursor)) {
            $cursorExecution = $dbForInternal->getDocument('builds', $cursor);

            if ($cursorExecution->isEmpty()) {
                throw new Exception("Execution '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $results = $dbForInternal->find('builds', $queries, $limit, $offset, [], [Database::ORDER_DESC], $cursorExecution ?? null, $cursorDirection);

        $sum = $dbForInternal->count('builds', $queries, APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'builds' => $results,
            'sum' => $sum,
        ]), Response::MODEL_BUILD_LIST);
    });

App::get('/v1/builds/:buildId')
    ->groups(['api', 'functions'])
    ->desc('Get Build')
    ->label('scope', 'execution.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getBuild')
    ->label('sdk.description', '/docs/references/functions/get-build.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BUILD)
    ->param('buildId', '', new UID(), 'Build unique ID.')
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($buildId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        
        Authorization::disable();
        $build = $dbForInternal->getDocument('builds', $buildId);
        Authorization::reset();

        if ($build->isEmpty()) {
            throw new Exception('Build not found', 404);
        }

        $response->dynamic($build, Response::MODEL_BUILD);
    });