<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Auth;
use Appwrite\Event\Build;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Validator\Event as ValidatorEvent;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\CustomId;
use Utopia\Database\Validator\UID;
use Appwrite\Stats\Stats;
use Utopia\Storage\Device;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Appwrite\Utopia\Response;
use Utopia\Swoole\Request;
use Appwrite\Task\Validator\Cron;
use Utopia\App;
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
use Executor\Executor;
use Utopia\CLI\Console;
use Utopia\Database\Validator\Permissions;
use Utopia\Validator\Boolean;

include_once __DIR__ . '/../shared/api.php';

App::post('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('Create Function')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/functions/create-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new CustomId(), 'Function ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Function name. Max length: 128 chars.')
    ->param('execute', [], new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of strings with execution permissions. By default no user is granted with any execute permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed, each 64 characters long.')
    ->param('runtime', '', new WhiteList(array_keys(Config::getParam('runtimes')), true), 'Execution runtime.')
    ->param('vars', [], new Assoc(), 'Key-value JSON object that will be passed to the function as environment variables.', true)
    ->param('events', [], new ArrayList(new ValidatorEvent(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.', true)
    ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
    ->param('timeout', 15, new Range(1, (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)), 'Function maximum execution time in seconds.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $functionId, string $name, array $execute, string $runtime, array $vars, array $events, string $schedule, int $timeout, Response $response, Database $dbForProject, Event $eventsInstance) {

        $functionId = ($functionId == 'unique()') ? $dbForProject->getId() : $functionId;
        $function = $dbForProject->createDocument('functions', new Document([
            '$id' => $functionId,
            'execute' => $execute,
            'status' => 'disabled',
            'name' => $name,
            'runtime' => $runtime,
            'deployment' => '',
            'vars' => $vars,
            'events' => $events,
            'schedule' => $schedule,
            'schedulePrevious' => 0,
            'scheduleNext' => 0,
            'timeout' => $timeout,
            'search' => implode(' ', [$functionId, $name, $runtime]),
        ]));

        $eventsInstance->setParam('functionId', $function->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($function, Response::MODEL_FUNCTION);
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
    ->param('limit', 25, new Range(0, 100), 'Maximum number of functions to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the function used as the starting point for the query, excluding the function itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor, can be either \'before\' or \'after\'.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $search, int $limit, int $offset, string $cursor, string $cursorDirection, string $orderType, Response $response, Database $dbForProject) {

        if (!empty($cursor)) {
            $cursorFunction = $dbForProject->getDocument('functions', $cursor);

            if ($cursorFunction->isEmpty()) {
                throw new Exception("Function '{$cursor}' for the 'cursor' value not found.", 400, Exception::GENERAL_CURSOR_NOT_FOUND);
            }
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $response->dynamic(new Document([
            'functions' => $dbForProject->find('functions', $queries, $limit, $offset, [], [$orderType], $cursorFunction ?? null, $cursorDirection),
            'total' => $dbForProject->count('functions', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_FUNCTION_LIST);
    });

App::get('/v1/functions/runtimes')
    ->groups(['api', 'functions'])
    ->desc('List runtimes')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listRuntimes')
    ->label('sdk.description', '/docs/references/functions/list-runtimes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_RUNTIME_LIST)
    ->inject('response')
    ->action(function (Response $response) {

        $runtimes = Config::getParam('runtimes');

        $runtimes = array_map(function ($key) use ($runtimes) {
            $runtimes[$key]['$id'] = $key;
            return $runtimes[$key];
        }, array_keys($runtimes));

        $response->dynamic(new Document([
            'total' => count($runtimes),
            'runtimes' => $runtimes
        ]), Response::MODEL_RUNTIME_LIST);
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
    ->param('functionId', '', new UID(), 'Function ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, Response $response, Database $dbForProject) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
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
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d']), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, string $range, Response $response, Database $dbForProject) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
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

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
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
                        $diff = match ($period) { // convert period to seconds for unix timestamp math
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
    ->label('event', 'functions.[functionId].update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/functions/update-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('name', '', new Text(128), 'Function name. Max length: 128 chars.')
    ->param('execute', [], new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of strings with execution permissions. By default no user is granted with any execute permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed, each 64 characters long.')
    ->param('vars', [], new Assoc(), 'Key-value JSON object that will be passed to the function as environment variables.', true)
    ->param('events', [], new ArrayList(new ValidatorEvent(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.', true)
    ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
    ->param('timeout', 15, new Range(1, (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)), 'Maximum execution time in seconds.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->action(function (string $functionId, string $name, array $execute, array $vars, array $events, string $schedule, int $timeout, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        $original = $function->getAttribute('schedule', '');
        $cron = (!empty($function->getAttribute('deployment', null)) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (!empty($function->getAttribute('deployment', null)) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;

        $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
            'execute' => $execute,
            'name' => $name,
            'vars' => $vars,
            'events' => $events,
            'schedule' => $schedule,
            'scheduleNext' => (int)$next,
            'timeout' => $timeout,
            'search' => implode(' ', [$functionId, $name, $function->getAttribute('runtime')]),
        ])));

        if ($next && $schedule !== $original) {
            // Async task reschedule
            $functionEvent = new Func();
            $functionEvent
                ->setFunction($function)
                ->setType('schedule')
                ->setUser($user)
                ->setProject($project);

            $functionEvent->schedule($next);
        }

        $eventsInstance->setParam('functionId', $function->getId());

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::patch('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Update Function Deployment')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].deployments.[deploymentId].update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'updateDeployment')
    ->label('sdk.description', '/docs/references/functions/update-function-deployment.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('events')
    ->action(function (string $functionId, string $deploymentId, Response $response, Database $dbForProject, Document $project, Event $events) {

        $function = $dbForProject->getDocument('functions', $functionId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404, Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($build->isEmpty()) {
            throw new Exception('Build not found', 404, Exception::BUILD_NOT_FOUND);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Build not ready', 400, Exception::BUILD_NOT_READY);
        }

        $schedule = $function->getAttribute('schedule', '');
        $cron = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;

        $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
            'deployment' => $deployment->getId(),
            'scheduleNext' => (int)$next,
        ])));

        if ($next) { // Init first schedule
            $functionEvent = new Func();
            $functionEvent
                ->setType('schedule')
                ->setFunction($function)
                ->setProject($project);
            $functionEvent->schedule($next);
        }

        $events
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::delete('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Delete Function')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/functions/delete-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deletes')
    ->inject('events')
    ->action(function (string $functionId, Response $response, Database $dbForProject, Delete $deletes, Event $events) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('functions', $function->getId())) {
            throw new Exception('Failed to remove function from DB', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($function);

        $events->setParam('functionId', $function->getId());

        $response->noContent();
    });

App::post('/v1/functions/:functionId/deployments')
    ->groups(['api', 'functions'])
    ->desc('Create Deployment')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].deployments.[deploymentId].create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createDeployment')
    ->label('sdk.description', '/docs/references/functions/create-deployment.md')
    ->label('sdk.packaging', true)
    ->label('sdk.request.type', 'multipart/form-data')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DEPLOYMENT)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('entrypoint', '', new Text('1028'), 'Entrypoint File.')
    ->param('code', [], new File(), 'Gzip file with your code package. When used with the Appwrite CLI, pass the path to your code directory, and the CLI will automatically package your code. Use a path that is within the current directory.', false)
    ->param('activate', false, new Boolean(true), 'Automatically activate the deployment when it is finished building.', false)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('events')
    ->inject('project')
    ->inject('deviceFunctions')
    ->inject('deviceLocal')
    ->action(function (string $functionId, string $entrypoint, mixed $code, bool $activate, Request $request, Response $response, Database $dbForProject, Stats $usage, Event $events, Document $project, Device $deviceFunctions, Device $deviceLocal) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        $file = $request->getFiles('code');
        $fileExt = new FileExt([FileExt::TYPE_GZIP]);
        $fileSizeValidator = new FileSize(App::getEnv('_APP_FUNCTIONS_SIZE_LIMIT', 0));
        $upload = new Upload();

        if (empty($file)) {
            throw new Exception('No file sent', 400, Exception::STORAGE_FILE_EMPTY);
        }

        // Make sure we handle a single file and multiple files the same way
        $fileName = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $fileTmpName = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        if (!$fileExt->isValid($file['name'])) { // Check if file type is allowed
            throw new Exception('File type not allowed', 400, Exception::STORAGE_FILE_TYPE_UNSUPPORTED);
        }

        $contentRange = $request->getHeader('content-range');
        $deploymentId = $dbForProject->getId();
        $chunk = 1;
        $chunks = 1;

        if (!empty($contentRange)) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $fileSize = $request->getContentRangeSize();
            $deploymentId = $request->getHeader('x-appwrite-id', $deploymentId);
            if (is_null($start) || is_null($end) || is_null($fileSize)) {
                throw new Exception('Invalid content-range header', 400, Exception::STORAGE_INVALID_CONTENT_RANGE);
            }

            if ($end === $fileSize) {
                //if it's a last chunks the chunk size might differ, so we set the $chunks and $chunk to notify it's last chunk
                $chunks = $chunk = -1;
            } else {
                // Calculate total number of chunks based on the chunk size i.e ($rangeEnd - $rangeStart)
                $chunks = (int) ceil($fileSize / ($end + 1 - $start));
                $chunk = (int) ($start / ($end + 1 - $start)) + 1;
            }
        }

        if (!$fileSizeValidator->isValid($fileSize)) { // Check if file size is exceeding allowed limit
            throw new Exception('File size not allowed', 400, Exception::STORAGE_INVALID_FILE_SIZE);
        }

        if (!$upload->isValid($fileTmpName)) {
            throw new Exception('Invalid file', 403, Exception::STORAGE_INVALID_FILE);
        }

        // Save to storage
        $fileSize ??= $deviceLocal->getFileSize($fileTmpName);
        $path = $deviceFunctions->getPath($deploymentId . '.' . \pathinfo($fileName, PATHINFO_EXTENSION));

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        $metadata = ['content_type' => $deviceLocal->getFileMimeType($fileTmpName)];
        if (!$deployment->isEmpty()) {
            $chunks = $deployment->getAttribute('chunksTotal', 1);
            $metadata = $deployment->getAttribute('metadata', []);
            if ($chunk === -1) {
                $chunk = $chunks;
            }
        }

        $chunksUploaded = $deviceFunctions->upload($fileTmpName, $path, $chunk, $chunks, $metadata);

        if (empty($chunksUploaded)) {
            throw new Exception('Failed moving file', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $activate = (bool) filter_var($activate, FILTER_VALIDATE_BOOLEAN);

        if ($chunksUploaded === $chunks) {
            if ($activate) {
                // Remove deploy for all other deployments.
                $activeDeployments = $dbForProject->find('deployments', [
                    new Query('activate', Query::TYPE_EQUAL, [true]),
                    new Query('resourceId', Query::TYPE_EQUAL, [$functionId]),
                    new Query('resourceType', Query::TYPE_EQUAL, ['functions'])
                ]);

                foreach ($activeDeployments as $activeDeployment) {
                    $activeDeployment->setAttribute('activate', false);
                    $dbForProject->updateDocument('deployments', $activeDeployment->getId(), $activeDeployment);
                }
            }

            $fileSize = $deviceFunctions->getFileSize($path);

            if ($deployment->isEmpty()) {
                $deployment = $dbForProject->createDocument('deployments', new Document([
                    '$id' => $deploymentId,
                    '$read' => ['role:all'],
                    '$write' => ['role:all'],
                    'resourceId' => $function->getId(),
                    'resourceType' => 'functions',
                    'entrypoint' => $entrypoint,
                    'path' => $path,
                    'size' => $fileSize,
                    'search' => implode(' ', [$deploymentId, $entrypoint]),
                    'activate' => $activate,
                    'metadata' => $metadata,
                ]));
            } else {
                $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment->setAttribute('size', $fileSize)->setAttribute('metadata', $metadata));
            }

            // Start the build
            $buildEvent = new Build();
            $buildEvent
                ->setType(BUILD_TYPE_DEPLOYMENT)
                ->setResource($function)
                ->setDeployment($deployment)
                ->setProject($project)
                ->trigger();

            $usage->setParam('storage', $deployment->getAttribute('size', 0));
        } else {
            if ($deployment->isEmpty()) {
                $deployment = $dbForProject->createDocument('deployments', new Document([
                    '$id' => $deploymentId,
                    '$read' => ['role:all'],
                    '$write' => ['role:all'],
                    'resourceId' => $function->getId(),
                    'resourceType' => 'functions',
                    'entrypoint' => $entrypoint,
                    'path' => $path,
                    'size' => $fileSize,
                    'chunksTotal' => $chunks,
                    'chunksUploaded' => $chunksUploaded,
                    'search' => implode(' ', [$deploymentId, $entrypoint]),
                    'activate' => $activate,
                    'metadata' => $metadata,
                ]));
            } else {
                $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment->setAttribute('chunksUploaded', $chunksUploaded)->setAttribute('metadata', $metadata));
            }
        }

        $metadata = null;

        $events
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    });

App::get('/v1/functions/:functionId/deployments')
    ->groups(['api', 'functions'])
    ->desc('List Deployments')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listDeployments')
    ->label('sdk.description', '/docs/references/functions/list-deployments.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DEPLOYMENT_LIST)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of deployments to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the deployment used as the starting point for the query, excluding the deployment itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor, can be either \'before\' or \'after\'.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, string $search, int $limit, int $offset, string $cursor, string $cursorDirection, string $orderType, Response $response, Database $dbForProject) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        if (!empty($cursor)) {
            $cursorDeployment = $dbForProject->getDocument('deployments', $cursor);

            if ($cursorDeployment->isEmpty()) {
                throw new Exception("Tag '{$cursor}' for the 'cursor' value not found.", 400, Exception::GENERAL_CURSOR_NOT_FOUND);
            }
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $queries[] = new Query('resourceId', Query::TYPE_EQUAL, [$function->getId()]);
        $queries[] = new Query('resourceType', Query::TYPE_EQUAL, ['functions']);

        $results = $dbForProject->find('deployments', $queries, $limit, $offset, [], [$orderType], $cursorDeployment ?? null, $cursorDirection);
        $total = $dbForProject->count('deployments', $queries, APP_LIMIT_COUNT);

        foreach ($results as $result) {
            $build = $dbForProject->getDocument('builds', $result->getAttribute('buildId', ''));
            $result->setAttribute('status', $build->getAttribute('status', 'processing'));
            $result->setAttribute('buildStderr', $build->getAttribute('stderr', ''));
            $result->setAttribute('buildStdout', $build->getAttribute('stdout', ''));
        }

        $response->dynamic(new Document([
            'deployments' => $results,
            'total' => $total,
        ]), Response::MODEL_DEPLOYMENT_LIST);
    });

App::get('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Get Deployment')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getDeployment')
    ->label('sdk.description', '/docs/references/functions/get-deployment.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DEPLOYMENT)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, string $deploymentId, Response $response, Database $dbForProject) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception('Deployment not found', 404, Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404, Exception::DEPLOYMENT_NOT_FOUND);
        }

        $response->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    });

App::delete('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Delete Deployment')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].deployments.[deploymentId].delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'deleteDeployment')
    ->label('sdk.description', '/docs/references/functions/delete-deployment.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('deletes')
    ->inject('events')
    ->inject('deviceFunctions')
    ->action(function (string $functionId, string $deploymentId, Response $response, Database $dbForProject, Stats $usage, Delete $deletes, Event $events, Device $deviceFunctions) {

        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404, Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception('Deployment not found', 404, Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deviceFunctions->delete($deployment->getAttribute('path', ''))) {
            if (!$dbForProject->deleteDocument('deployments', $deployment->getId())) {
                throw new Exception('Failed to remove deployment from DB', 500, Exception::GENERAL_SERVER_ERROR);
            }
        }

        if ($function->getAttribute('deployment') === $deployment->getId()) { // Reset function deployment
            $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
                'deployment' => '',
            ])));
        }

        $usage
            ->setParam('storage', $deployment->getAttribute('size', 0) * -1);

        $events
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($deployment);

        $response->noContent();
    });

App::post('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('Create Execution')
    ->label('scope', 'execution.write')
    ->label('event', 'functions.[functionId].executions.[executionId].create')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createExecution')
    ->label('sdk.description', '/docs/references/functions/create-execution.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION)
    ->label('abuse-limit', 60)
    ->label('abuse-time', 60)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('data', '', new Text(8192), 'String of custom data to send to function.', true)
    ->param('async', true, new Boolean(), 'Execute code asynchronously. Default value is true.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('events')
    ->inject('usage')
    ->action(function (string $functionId, string $data, bool $async, Response $response, Document $project, Database $dbForProject, Document $user, Event $events, Stats $usage) {

        $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        $runtimes = Config::getParam('runtimes', []);

        $runtime = (isset($runtimes[$function->getAttribute('runtime', '')])) ? $runtimes[$function->getAttribute('runtime', '')] : null;

        if (\is_null($runtime)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported', 400, Exception::FUNCTION_RUNTIME_UNSUPPORTED);
        }

        $deployment = Authorization::skip(fn () => $dbForProject->getDocument('deployments', $function->getAttribute('deployment', '')));

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception('Deployment not found. Create a deployment before trying to execute a function', 404, Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found. Create a deployment before trying to execute a function', 404, Exception::DEPLOYMENT_NOT_FOUND);
        }

        /** Check if build has completed */
        $build = Authorization::skip(fn () => $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', '')));
        if ($build->isEmpty()) {
            throw new Exception('Build not found', 404, Exception::BUILD_NOT_FOUND);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Build not ready', 400, Exception::BUILD_NOT_READY);
        }

        $validator = new Authorization('execute');

        if (!$validator->isValid($function->getAttribute('execute'))) { // Check if user has write access to execute function
            throw new Exception($validator->getDescription(), 401, Exception::USER_UNAUTHORIZED);
        }

        $executionId = $dbForProject->getId();

        /** @var Document $execution */
        $execution = Authorization::skip(fn () => $dbForProject->createDocument('executions', new Document([
            '$id' => $executionId,
            '$read' => (!$user->isEmpty()) ? ['user:' . $user->getId()] : [],
            '$write' => [],
            'functionId' => $function->getId(),
            'deploymentId' => $deployment->getId(),
            'trigger' => 'http', // http / schedule / event
            'status' => 'waiting', // waiting / processing / completed / failed
            'statusCode' => 0,
            'response' => '',
            'stderr' => '',
            'time' => 0.0,
            'search' => implode(' ', [$functionId, $executionId]),
        ])));

        $jwt = ''; // initialize
        if (!$user->isEmpty()) { // If userId exists, generate a JWT for function
            $sessions = $user->getAttribute('sessions', []);
            $current = new Document();

            foreach ($sessions as $session) {
                /** @var Utopia\Database\Document $session */
                if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                    $current = $session;
                }
            }

            if (!$current->isEmpty()) {
                $jwtObj = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.
                $jwt = $jwtObj->encode([
                    'userId' => $user->getId(),
                    'sessionId' => $current->getId(),
                ]);
            }
        }

        $events
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setContext('function', $function);

        if ($async) {
            $event = new Func();
            $event
                ->setType('http')
                ->setExecution($execution)
                ->setFunction($function)
                ->setData($data)
                ->setJWT($jwt)
                ->setProject($project)
                ->setUser($user);

            $event->trigger();

            return $response
                ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                ->dynamic($execution, Response::MODEL_EXECUTION);
        }

        /** Collect environment variables */
        $vars = \array_merge($function->getAttribute('vars', []), [
            'APPWRITE_FUNCTION_ID' => $function->getId(),
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
            'APPWRITE_FUNCTION_TRIGGER' => 'http',
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
            'APPWRITE_FUNCTION_DATA' => $data,
            'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
            'APPWRITE_FUNCTION_USER_ID' => $user->getId(),
            'APPWRITE_FUNCTION_JWT' => $jwt,
        ]);

        /** Execute function */
        $executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));
        $executionResponse = [];
        try {
            $executionResponse = $executor->createExecution(
                projectId: $project->getId(),
                deploymentId: $deployment->getId(),
                path: $build->getAttribute('outputPath', ''),
                vars: $vars,
                data: $data,
                entrypoint: $deployment->getAttribute('entrypoint', ''),
                runtime: $function->getAttribute('runtime', ''),
                timeout: $function->getAttribute('timeout', 0),
                baseImage: $runtime['image']
            );

            /** Update execution status */
            $execution->setAttribute('status', $executionResponse['status']);
            $execution->setAttribute('statusCode', $executionResponse['statusCode']);
            $execution->setAttribute('response', $executionResponse['response']);
            $execution->setAttribute('stderr', $executionResponse['stderr']);
            $execution->setAttribute('time', $executionResponse['time']);
        } catch (\Throwable $th) {
            $endtime = \microtime(true);
            $time = $endtime - $execution->getCreatedAt();
            $execution->setAttribute('time', $time);
            $execution->setAttribute('status', 'failed');
            $execution->setAttribute('statusCode', $th->getCode());
            $execution->setAttribute('stderr', $th->getMessage());
            Console::error($th->getMessage());
        }

        Authorization::skip(fn () => $dbForProject->updateDocument('executions', $executionId, $execution));

        $usage
            ->setParam('functionId', $function->getId())
            ->setParam('functionExecution', 1)
            ->setParam('functionStatus', $execution->getAttribute('status', ''))
            ->setParam('functionExecutionTime', $execution->getAttribute('time') * 1000); // ms

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($execution, Response::MODEL_EXECUTION);
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
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('limit', 25, new Range(0, 100), 'Maximum number of executions to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('cursor', '', new UID(), 'ID of the execution used as the starting point for the query, excluding the execution itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor, can be either \'before\' or \'after\'.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, int $limit, int $offset, string $search, string $cursor, string $cursorDirection, Response $response, Database $dbForProject) {

        $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        if (!empty($cursor)) {
            $cursorExecution = $dbForProject->getDocument('executions', $cursor);

            if ($cursorExecution->isEmpty()) {
                throw new Exception("Execution '{$cursor}' for the 'cursor' value not found.", 400, Exception::GENERAL_CURSOR_NOT_FOUND);
            }
        }

        $queries = [
            new Query('functionId', Query::TYPE_EQUAL, [$function->getId()])
        ];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $results = $dbForProject->find('executions', $queries, $limit, $offset, [], [Database::ORDER_DESC], $cursorExecution ?? null, $cursorDirection);
        $total = $dbForProject->count('executions', $queries, APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'executions' => $results,
            'total' => $total,
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
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('executionId', '', new UID(), 'Execution ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, string $executionId, Response $response, Database $dbForProject) {

        $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        $execution = $dbForProject->getDocument('executions', $executionId);

        if ($execution->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Execution not found', 404, Exception::EXECUTION_NOT_FOUND);
        }

        if ($execution->isEmpty()) {
            throw new Exception('Execution not found', 404, Exception::EXECUTION_NOT_FOUND);
        }

        $response->dynamic($execution, Response::MODEL_EXECUTION);
    });

App::post('/v1/functions/:functionId/deployments/:deploymentId/builds/:buildId')
    ->groups(['api', 'functions'])
    ->desc('Retry Build')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].deployments.[deploymentId].update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'retryBuild')
    ->label('sdk.description', '/docs/references/functions/retry-build.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->param('buildId', '', new UID(), 'Build unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('events')
    ->action(function (string $functionId, string $deploymentId, string $buildId, Response $response, Database $dbForProject, Document $project, Event $events) {

        $function = $dbForProject->getDocument('functions', $functionId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404, Exception::FUNCTION_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404, Exception::DEPLOYMENT_NOT_FOUND);
        }

        $build = Authorization::skip(fn () => $dbForProject->getDocument('builds', $buildId));

        if ($build->isEmpty()) {
            throw new Exception('Build not found', 404, Exception::BUILD_NOT_FOUND);
        }

        if ($build->getAttribute('status') !== 'failed') {
            throw new Exception('Build not failed', 400, Exception::BUILD_IN_PROGRESS);
        }

        $events
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        // Retry the build
        $buildEvent = new Build();
        $buildEvent
            ->setType(BUILD_TYPE_RETRY)
            ->setResource($function)
            ->setDeployment($deployment)
            ->setProject($project)
            ->trigger();

        $response->noContent();
    });
