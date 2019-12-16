<?php

global $utopia, $request, $response, $consoleDB, $project;

use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Range;
use Utopia\Validator\URL;
use Task\Validator\Cron;
use Database\Database;
use Database\Document;
use Database\Validator\UID;
use OpenSSL\OpenSSL;
use Cron\CronExpression;

include_once '../shared/api.php';

$utopia->get('/v1/tasks')
    ->desc('List Tasks')
    ->label('scope', 'tasks.read')
    ->label('sdk.namespace', 'tasks')
    ->label('sdk.method', 'listTasks')
    ->action(
        function () use ($request, $response, $consoleDB, $project) {
            $tasks = $project->getAttribute('tasks', []);

            foreach ($tasks as $task) { /* @var $task Document */
                $httpPass = json_decode($task->getAttribute('httpPass', '{}'), true);

                if (empty($httpPass) || !isset($httpPass['version'])) {
                    continue;
                }

                $key = $request->getServer('_APP_OPENSSL_KEY_V'.$httpPass['version']);

                $task->setAttribute('httpPass', OpenSSL::decrypt($httpPass['data'], $httpPass['method'], $key, 0, hex2bin($httpPass['iv']), hex2bin($httpPass['tag'])));
            }

            $response->json($tasks);
        }
    );

$utopia->get('/v1/tasks/:taskId')
    ->desc('Get Task')
    ->label('scope', 'tasks.read')
    ->label('sdk.namespace', 'tasks')
    ->label('sdk.method', 'getTask')
    ->param('taskId', null, function () { return new UID(); }, 'Task unique ID.')
    ->action(
        function ($taskId) use ($request, $response, $consoleDB, $project) {
            $task = $project->search('$uid', $taskId, $project->getAttribute('tasks', []));

            if (empty($task) && $task instanceof Document) {
                throw new Exception('Task not found', 404);
            }

            $httpPass = json_decode($task->getAttribute('httpPass', '{}'), true);

            if (!empty($httpPass) && isset($httpPass['version'])) {
                $key = $request->getServer('_APP_OPENSSL_KEY_V'.$httpPass['version']);
                $task->setAttribute('httpPass', OpenSSL::decrypt($httpPass['data'], $httpPass['method'], $key, 0, hex2bin($httpPass['iv']), hex2bin($httpPass['tag'])));
            }

            $response->json($task->getArrayCopy());
        }
    );

$utopia->post('/v1/tasks')
    ->desc('Create Task')
    ->label('scope', 'tasks.write')
    ->label('sdk.namespace', 'tasks')
    ->label('sdk.method', 'createTask')
    ->param('name', null, function () { return new Text(256); }, 'Task name')
    ->param('status', null, function () { return new WhiteList(['play', 'pause']); }, 'Task status')
    ->param('schedule', null, function () { return new Cron(); }, 'Task schedule syntax')
    ->param('security', null, function () { return new Range(0, 1); }, 'Certificate verification, 0 for disabled or 1 for enabled')
    ->param('httpMethod', '', function () { return new WhiteList(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT']); }, 'Task HTTP method')
    ->param('httpUrl', '', function () { return new URL(); }, 'Task HTTP URL')
    ->param('httpHeaders', null, function () { return new ArrayList(new Text(256)); }, 'Task HTTP headers list', true)
    ->param('httpUser', '', function () { return new Text(256); }, 'Task HTTP user', true)
    ->param('httpPass', '', function () { return new Text(256); }, 'Task HTTP password', true)
    ->action(
        function ($name, $status, $schedule, $security, $httpMethod, $httpUrl, $httpHeaders, $httpUser, $httpPass) use ($request, $response, $consoleDB, $project) {
            $cron = CronExpression::factory($schedule);
            $next = ($status == 'play') ? $cron->getNextRunDate()->format('U') : null;

            $key = $request->getServer('_APP_OPENSSL_KEY_V1');
            $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
            $tag = null;
            $httpPass = json_encode([
                'data' => OpenSSL::encrypt($httpPass, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag),
                'method' => OpenSSL::CIPHER_AES_128_GCM,
                'iv' => bin2hex($iv),
                'tag' => bin2hex($tag),
                'version' => '1',
            ]);

            $task = $consoleDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_TASKS,
                '$permissions' => [
                    'read' => ['team:'.$project->getAttribute('teamId', null)],
                    'write' => ['team:'.$project->getAttribute('teamId', null).'/owner', 'team:'.$project->getAttribute('teamId', null).'/developer'],
                ],
                'name' => $name,
                'status' => $status,
                'schedule' => $schedule,
                'updated' => time(),
                'previous' => null,
                'next' => $next,
                'security' => (int) $security,
                'httpMethod' => $httpMethod,
                'httpUrl' => $httpUrl,
                'httpHeaders' => $httpHeaders,
                'httpUser' => $httpUser,
                'httpPass' => $httpPass,
                'log' => '{}',
                'failures' => 0,
            ]);

            if (false === $task) {
                throw new Exception('Failed saving tasks to DB', 500);
            }

            $project->setAttribute('tasks', $task, Document::SET_TYPE_APPEND);

            $project = $consoleDB->updateDocument($project->getArrayCopy());

            if (false === $project) {
                throw new Exception('Failed saving project to DB', 500);
            }

            if ($next) {
                ResqueScheduler::enqueueAt($next, 'v1-tasks', 'TasksV1', $task->getArrayCopy());
            }

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($task->getArrayCopy())
            ;
        }
    );

$utopia->put('/v1/tasks/:taskId')
    ->desc('Update Task')
    ->label('scope', 'tasks.write')
    ->label('sdk.namespace', 'tasks')
    ->label('sdk.method', 'updateTask')
    ->param('taskId', null, function () { return new UID(); }, 'Task unique ID.')
    ->param('name', null, function () { return new Text(256); }, 'Task name')
    ->param('status', null, function () { return new WhiteList(['play', 'pause']); }, 'Task status')
    ->param('schedule', null, function () { return new Cron(); }, 'Task schedule syntax')
    ->param('security', null, function () { return new Range(0, 1); }, 'Certificate verification, 0 for disabled or 1 for enabled')
    ->param('httpMethod', '', function () { return new WhiteList(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT']); }, 'Task HTTP method')
    ->param('httpUrl', '', function () { return new URL(); }, 'Task HTTP URL')
    ->param('httpHeaders', null, function () { return new ArrayList(new Text(256)); }, 'Task HTTP headers list', true)
    ->param('httpUser', '', function () { return new Text(256); }, 'Task HTTP user', true)
    ->param('httpPass', '', function () { return new Text(256); }, 'Task HTTP password', true)
    ->action(
        function ($taskId, $name, $status, $schedule, $security, $httpMethod, $httpUrl, $httpHeaders, $httpUser, $httpPass) use ($request, $response, $consoleDB, $project) {
            $task = $project->search('$uid', $taskId, $project->getAttribute('tasks', []));

            if (empty($task) && $task instanceof Document) {
                throw new Exception('Task not found', 404);
            }

            $cron = CronExpression::factory($schedule);
            $next = ($status == 'play') ? $cron->getNextRunDate()->format('U') : null;

            $key = $request->getServer('_APP_OPENSSL_KEY_V1');
            $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
            $tag = null;
            $httpPass = json_encode([
                'data' => OpenSSL::encrypt($httpPass, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag),
                'method' => OpenSSL::CIPHER_AES_128_GCM,
                'iv' => bin2hex($iv),
                'tag' => bin2hex($tag),
                'version' => '1',
            ]);

            $task
                ->setAttribute('name', $name)
                ->setAttribute('status', $status)
                ->setAttribute('schedule', $schedule)
                ->setAttribute('updated', time())
                ->setAttribute('next', $next)
                ->setAttribute('security', (int) $security)
                ->setAttribute('httpMethod', $httpMethod)
                ->setAttribute('httpUrl', $httpUrl)
                ->setAttribute('httpHeaders', $httpHeaders)
                ->setAttribute('httpUser', $httpUser)
                ->setAttribute('httpPass', $httpPass)
            ;

            if (false === $consoleDB->updateDocument($task->getArrayCopy())) {
                throw new Exception('Failed saving tasks to DB', 500);
            }

            if ($next) {
                ResqueScheduler::enqueueAt($next, 'v1-tasks', 'TasksV1', $task->getArrayCopy());
            }

            $response->json($task->getArrayCopy());
        }
    );

$utopia->delete('/v1/tasks/:taskId')
    ->desc('Delete Task')
    ->label('scope', 'tasks.write')
    ->label('sdk.namespace', 'tasks')
    ->label('sdk.method', 'deleteTask')
    ->param('taskId', null, function () { return new UID(); }, 'Task unique ID.')
    ->action(
        function ($taskId) use ($response, $consoleDB, $project) {
            $task = $project->search('$uid', $taskId, $project->getAttribute('tasks', []));

            if (empty($task) && $task instanceof Document) {
                throw new Exception('Task not found', 404);
            }

            if (!$consoleDB->deleteDocument($task->getUid())) {
                throw new Exception('Failed to remove tasks from DB', 500);
            }

            $response->noContent();
        }
    );