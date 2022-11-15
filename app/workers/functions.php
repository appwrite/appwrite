<?php

require_once __DIR__ . '/../worker.php';

use Utopia\Queue;
use Utopia\Queue\Message;
use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Usage\Stats;
use Appwrite\Utopia\Response\Model\Execution;
use Executor\Executor;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;

Authorization::disable();
Authorization::setDefaultStatus(false);

global $connection;
global $workerNumber;

$executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));

$execute = function (
    Document $project,
    Document $function,
    Database $dbForProject,
    string $trigger,
    string $executionId = null,
    string $event = null,
    string $eventData = null,
    string $data = null,
    ?Document $user = null,
    string $jwt = null
) use ($executor, $register) {

    $user ??= new Document();
    $functionId = $function->getId();
    $deploymentId = $function->getAttribute('deployment', '');

    /** Check if deployment exists */
    $deployment = $dbForProject->getDocument('deployments', $deploymentId);

    if ($deployment->getAttribute('resourceId') !== $functionId) {
        throw new Exception('Deployment not found. Create deployment before trying to execute a function');
    }

    if ($deployment->isEmpty()) {
        throw new Exception('Deployment not found. Create deployment before trying to execute a function');
    }

    /** Check if build has exists */
    $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));
    if ($build->isEmpty()) {
        throw new Exception('Build not found');
    }

    if ($build->getAttribute('status') !== 'ready') {
        throw new Exception('Build not ready');
    }

    /** Check if  runtime is supported */
    $runtimes = Config::getParam('runtimes', []);

    if (!\array_key_exists($function->getAttribute('runtime'), $runtimes)) {
        throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
    }

    $runtime = $runtimes[$function->getAttribute('runtime')];

    /** Create execution or update execution status */
    $execution = $dbForProject->getDocument('executions', $executionId ?? '');
    if ($execution->isEmpty()) {
        $executionId = ID::unique();
        $execution = $dbForProject->createDocument('executions', new Document([
            '$id' => $executionId,
            '$permissions' => $user->isEmpty() ? [] : [Permission::read(Role::user($user->getId()))],
            'functionId' => $functionId,
            'deploymentId' => $deploymentId,
            'trigger' => $trigger,
            'status' => 'waiting',
            'statusCode' => 0,
            'response' => '',
            'stderr' => '',
            'duration' => 0.0,
            'search' => implode(' ', [$functionId, $executionId]),
        ]));

        if ($execution->isEmpty()) {
            throw new Exception('Failed to create or read execution');
        }
    }
    $execution->setAttribute('status', 'processing');
    $execution = $dbForProject->updateDocument('executions', $executionId, $execution);

    if ($build->getAttribute('status') !== 'ready') {
        throw new Exception('Build not ready');
    }

    /** Check if  runtime is supported */
    $runtimes = Config::getParam('runtimes', []);

    if (!\array_key_exists($function->getAttribute('runtime'), $runtimes)) {
        throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
    }

    $runtime = $runtimes[$function->getAttribute('runtime')];

    /** Create execution or update execution status */
    $execution = $dbForProject->getDocument('executions', $executionId ?? '');
    if ($execution->isEmpty()) {
        $executionId = ID::unique();
        $execution = $dbForProject->createDocument('executions', new Document([
            '$id' => $executionId,
            '$permissions' => $user->isEmpty() ? [] : [Permission::read(Role::user($user->getId()))],
            'functionId' => $functionId,
            'deploymentId' => $deploymentId,
            'trigger' => $trigger,
            'status' => 'waiting',
            'statusCode' => 0,
            'response' => '',
            'stderr' => '',
            'duration' => 0.0,
            'search' => implode(' ', [$functionId, $executionId]),
        ]));

        if ($execution->isEmpty()) {
            throw new Exception('Failed to create or read execution');
        }
    }
    $execution->setAttribute('status', 'processing');
    $execution = $dbForProject->updateDocument('executions', $executionId, $execution);

    $vars = array_reduce($function['vars'] ?? [], function (array $carry, Document $var) {
        $carry[$var->getAttribute('key')] = $var->getAttribute('value');
        return $carry;
    }, []);

    /** Collect environment variables */
    $vars = \array_merge($vars, [
        'APPWRITE_FUNCTION_ID' => $functionId,
        'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name'),
        'APPWRITE_FUNCTION_DEPLOYMENT' => $deploymentId,
        'APPWRITE_FUNCTION_TRIGGER' => $trigger,
        'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
        'APPWRITE_FUNCTION_EVENT' => $event ?? '',
        'APPWRITE_FUNCTION_EVENT_DATA' => $eventData ?? '',
        'APPWRITE_FUNCTION_DATA' => $data ?? '',
        'APPWRITE_FUNCTION_USER_ID' => $user->getId() ?? '',
        'APPWRITE_FUNCTION_JWT' => $jwt ?? '',
    ]);

    /** Execute function */
    try {
        $executionResponse = $executor->createExecution(
            projectId: $project->getId(),
            deploymentId: $deploymentId,
            path: $build->getAttribute('outputPath', ''),
            vars: $vars,
            entrypoint: $deployment->getAttribute('entrypoint', ''),
            data: $vars['APPWRITE_FUNCTION_DATA'] ?? '',
            runtime: $function->getAttribute('runtime', ''),
            baseImage: $runtime['image'],
            timeout: $function->getAttribute('timeout', 0)
        );

        /** Update execution status */
        $execution
            ->setAttribute('status', $executionResponse['status'])
            ->setAttribute('statusCode', $executionResponse['statusCode'])
            ->setAttribute('response', $executionResponse['response'])
            ->setAttribute('stdout', $executionResponse['stdout'])
            ->setAttribute('stderr', $executionResponse['stderr'])
            ->setAttribute('duration', $executionResponse['duration']);
    } catch (\Throwable $th) {
        $interval = (new \DateTime())->diff(new \DateTime($execution->getCreatedAt()));
        $execution
            ->setAttribute('duration', (float)$interval->format('%s.%f'))
            ->setAttribute('status', 'failed')
            ->setAttribute('statusCode', $th->getCode())
            ->setAttribute('stderr', $th->getMessage());
        Console::error($th->getMessage());
    }

    $execution = $dbForProject->updateDocument('executions', $executionId, $execution);

    /** Trigger Webhook */
    $executionModel = new Execution();
    $executionUpdate = new Event(Event::WEBHOOK_QUEUE_NAME, Event::WEBHOOK_CLASS_NAME);
    $executionUpdate
        ->setProject($project)
        ->setUser($user)
        ->setEvent('functions.[functionId].executions.[executionId].update')
        ->setParam('functionId', $function->getId())
        ->setParam('executionId', $execution->getId())
        ->setPayload($execution->getArrayCopy(array_keys($executionModel->getRules())))
        ->trigger();

    /** Trigger Functions */
    $executionUpdate
        ->setClass(Event::FUNCTIONS_CLASS_NAME)
        ->setQueue(Event::FUNCTIONS_QUEUE_NAME)
        ->trigger();

    /** Trigger realtime event */
    $allEvents = Event::generateEvents('functions.[functionId].executions.[executionId].update', [
        'functionId' => $function->getId(),
        'executionId' => $execution->getId()
    ]);
    $target = Realtime::fromPayload(
    // Pass first, most verbose event pattern
        event: $allEvents[0],
        payload: $execution
    );
    Realtime::send(
        projectId: 'console',
        payload: $execution->getArrayCopy(),
        events: $allEvents,
        channels: $target['channels'],
        roles: $target['roles']
    );
    Realtime::send(
        projectId: $project->getId(),
        payload: $execution->getArrayCopy(),
        events: $allEvents,
        channels: $target['channels'],
        roles: $target['roles']
    );

    /** Update usage stats */
    global $register;
    if (App::getEnv('_APP_USAGE_STATS', 'enabled') === 'enabled') {
        $statsd = $register->get('statsd');
        $usage = new Stats($statsd);
        $usage
            ->setParam('projectId', $project->getId())
            ->setParam('functionId', $function->getId())
            ->setParam('executions.{scope}.compute', 1)
            ->setParam('executionStatus', $execution->getAttribute('status', ''))
            ->setParam('executionTime', $execution->getAttribute('duration'))
            ->setParam('networkRequestSize', 0)
            ->setParam('networkResponseSize', 0)
            ->submit();
    }
};

$adapter  = new Queue\Adapter\Swoole($connection, $workerNumber, Event::FUNCTIONS_QUEUE_NAME);
$server   = new Queue\Server($adapter);

$server->job()
    ->inject('message')
    ->inject('dbForProject')
    ->action(function (Message $message, Database $dbForProject) use ($execute) {
        $args = $message->getPayload()['value'] ?? [];
        $type = $message->getPayload()['type'] ?? '';
        $events = $args['events'] ?? [];
        $project = new Document($args['project'] ?? []);
        $user = new Document($args['user'] ?? []);
        // Where $payload comes from
        $payload = json_encode($args['payload'] ?? []);

        if ($project->getId() === 'console') {
            return;
        }

        /**
         * Handle Event execution.
         */
        if (!empty($events)) {
            $limit = 30;
            $sum = $limit;
            $total = 0;
            $latestDocument = null;

            while ($sum === $limit) {
                $paginationQueries = [Query::limit($limit)];
                if ($latestDocument !== null) {
                    $paginationQueries[] =  Query::cursorAfter($latestDocument);
                }
                $results = $dbForProject->find('functions', \array_merge($paginationQueries, [
                    Query::orderAsc('name')
                ]));

                $sum = count($results);
                $total = $total + $sum;

                Console::log('Fetched ' . $sum . ' functions...');

                foreach ($results as $function) {
                    if (!array_intersect($events, $function->getAttribute('events', []))) {
                        continue;
                    }

                    Console::success('Iterating function: ' . $function->getAttribute('name'));

                    // As event, pass first, most verbose event pattern
                    call_user_func($execute, $project, $function, $dbForProject, 'event', null, $events[0], $payload, null, $user, null);

                    Console::success('Triggered function: ' . $events[0]);
                }

                $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
            }

            return;
        }

        /**
         * Handle Schedule and HTTP execution.
         */
        $user = new Document($args['user'] ?? []);
        $project = new Document($args['project'] ?? []);
        $execution = new Document($args['execution'] ?? []);
        $function = new Document($args['function'] ?? []);

        switch ($type) {
            case 'http':
                $jwt = $args['jwt'] ?? '';
                $data = $args['data'] ?? '';
                $function = $dbForProject->getDocument('functions', $execution->getAttribute('functionId'));
                call_user_func($execute, $project, $function, $dbForProject, 'http', $execution->getId(), null, null, $data, $user, $jwt);
                break;

            case 'schedule':
                /*
                 * 1. Get Original Task
                 * 2. Check for updates
                 *  If has updates skip task and don't reschedule
                 *  If status not equal to play skip task
                 * 3. Check next run date, update task and add new job at the given date
                 * 4. Execute task (set optional timeout)
                 * 5. Update task response to log
                 *      On success reset error count
                 *      On failure add error count
                 *      If error count bigger than allowed change status to pause
                 */

                call_user_func($execute, $project, $function, $dbForProject, 'schedule', null, null, null, null, null, null);
                break;
        }
    });

$server
    ->error()
    ->inject('error')
    ->inject('logger')
    ->inject('register')
    ->action(function ($error, $logger, $register) {

        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        if ($error instanceof PDOException) {
            throw $error;
        }

        if ($error->getCode() >= 500 || $error->getCode() === 0) {
            $log = new Log();

            $log->setNamespace("appwrite-worker");
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());
            $log->setAction('appwrite-worker-functions');
            $log->addTag('verboseType', get_class($error));
            $log->addTag('code', $error->getCode());
            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('detailedTrace', $error->getTrace());
            $log->addExtra('roles', \Utopia\Database\Validator\Authorization::$roles);

            $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $logger->addLog($log);
        }

        Console::error('[Error] Type: ' . get_class($error));
        Console::error('[Error] Message: ' . $error->getMessage());
        Console::error('[Error] File: ' . $error->getFile());
        Console::error('[Error] Line: ' . $error->getLine());

        $register->get('pools')->reclaim();
    });

$server->workerStart();
$server->start();
