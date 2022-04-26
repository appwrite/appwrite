<?php

use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Resque\Worker;
use Appwrite\Stats\Stats;
use Appwrite\Utopia\Response\Model\Execution;
use Cron\CronExpression;
use Executor\Executor;
use Swoole\Runtime;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

require_once __DIR__.'/../init.php';

Console::title('Functions V1 Worker');
Console::success(APP_NAME . ' functions worker v1 has started');

class FunctionsV1 extends Worker
{
    /**
     * @var Executor
     */
    private $executor = null;

    public array $args = [];

    public array $allowed = [];

    public function getName(): string {
        return "functions";
    }

    public function init(): void
    {
        $this->executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));
    }

    public function run(): void
    {
        $projectId = $this->args['projectId'] ?? '';
        $functionId = $this->args['functionId'] ?? '';
        $webhooks = $this->args['webhooks'] ?? [];
        $executionId = $this->args['executionId'] ?? '';
        $trigger = $this->args['trigger'] ?? '';
        $event = $this->args['event'] ?? '';
        $scheduleOriginal = $this->args['scheduleOriginal'] ?? '';
        $eventData = (!empty($this->args['eventData'])) ? json_encode($this->args['eventData']) : '';
        $data = $this->args['data'] ?? '';
        $userId = $this->args['userId'] ?? '';
        $jwt = $this->args['jwt'] ?? '';

        $database = $this->getProjectDB($projectId);

        switch ($trigger) {
            case 'event':
                $limit = 30;
                $sum = 30;
                $offset = 0;
                $functions = [];
                /** @var Document[] $functions */

                while ($sum >= $limit) {
                    $functions = Authorization::skip(fn() => $database->find('functions', [], $limit, $offset, ['name'], [Database::ORDER_ASC]));
                    $sum = \count($functions);
                    $offset = $offset + $limit;

                    Console::log('Fetched ' . $sum . ' functions...');

                    foreach ($functions as $function) {
                        $events =  $function->getAttribute('events', []);

                        if (!\in_array($event, $events)) {
                            continue;
                        }

                        Console::success('Iterating function: ' . $function->getAttribute('name'));

                        $this->execute(
                            projectId: $projectId,
                            function: $function,
                            dbForProject: $database,
                            executionId: $executionId,
                            webhooks: $webhooks,
                            trigger: $trigger,
                            event: $event,
                            eventData: $eventData,
                            data: $data,
                            userId: $userId,
                            jwt: $jwt
                        );

                        Console::success('Triggered function: ' . $event);
                    }
                }

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

                // Reschedule
                $function = Authorization::skip(fn() => $database->getDocument('functions', $functionId));

                if (empty($function->getId())) {
                    throw new Exception('Function not found ('.$functionId.')');
                }

                if ($scheduleOriginal && $scheduleOriginal !== $function->getAttribute('schedule')) { // Schedule has changed from previous run, ignore this run.
                    return;
                }

                $cron = new CronExpression($function->getAttribute('schedule'));
                $next = (int) $cron->getNextRunDate()->format('U');

                $function
                    ->setAttribute('scheduleNext', $next)
                    ->setAttribute('schedulePrevious', \time());

                $function = Authorization::skip(function()  use ($database, $function, $next, $functionId) {
                    $function = $database->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
                        'scheduleNext' => (int)$next,
                    ])));
    
                    if ($function === false) {
                        throw new Exception('Function update failed (' . $functionId . ')');
                    }
                    return $function;
                });

                ResqueScheduler::enqueueAt($next, Event::FUNCTIONS_QUEUE_NAME, Event::FUNCTIONS_CLASS_NAME, [
                    'projectId' => $projectId,
                    'webhooks' => $webhooks,
                    'functionId' => $function->getId(),
                    'userId' => $userId,
                    'executionId' => null,
                    'trigger' => 'schedule',
                    'scheduleOriginal' => $function->getAttribute('schedule', ''),
                ]);  // Async task reschedule

                $this->execute(
                    projectId: $projectId,
                    function: $function,
                    dbForProject: $database,
                    executionId: $executionId,
                    webhooks: $webhooks,
                    trigger: $trigger,
                    event: $event,
                    eventData: $eventData,
                    data: $data,
                    userId: $userId,
                    jwt: $jwt
                );
                break;

            case 'http':
                $function = Authorization::skip(fn() => $database->getDocument('functions', $functionId));

                if (empty($function->getId())) {
                    throw new Exception('Function not found ('.$functionId.')');
                }

                $this->execute(
                    projectId: $projectId,
                    function: $function,
                    dbForProject: $database,
                    executionId: $executionId,
                    webhooks: $webhooks,
                    trigger: $trigger,
                    event: $event,
                    eventData: $eventData,
                    data: $data,
                    userId: $userId,
                    jwt: $jwt
                );

                break;
        }
    }

    private function execute(
        string $projectId,
        Document $function,
        Database $dbForProject,
        string $executionId,
        array $webhooks,
        string $trigger,
        string $event, 
        string $eventData,
        string $data,
        string $userId,
        string $jwt
    ) {

        $functionId = $function->getId();
        $deploymentId = $function->getAttribute('deployment', '');

        /** Check if deployment exists */
        $deployment = Authorization::skip(fn() => $dbForProject->getDocument('deployments', $deploymentId));

        if ($deployment->getAttribute('resourceId') !== $functionId) {
            throw new Exception('Deployment not found. Create deployment before trying to execute a function', 404);
        }

        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found. Create deployment before trying to execute a function', 404);
        }

        /** Check if build has exists */
        $build = Authorization::skip(fn() => $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', '')));
        if ($build->isEmpty()) {
            throw new Exception('Build not found', 404);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Build not ready', 400);
        }

        /** Check if  runtime is supported */
        $runtimes = Config::getParam('runtimes', []);
        $runtime = (isset($runtimes[$function->getAttribute('runtime', '')])) ? $runtimes[$function->getAttribute('runtime', '')] : null;

        if (\is_null($runtime)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported', 400);
        }

        /** Create execution or update execution status */
        $execution = Authorization::skip(function() use ($dbForProject, &$executionId, $functionId, $deploymentId, $trigger, $userId) {
            $execution = $dbForProject->getDocument('executions', $executionId);
            if ($execution->isEmpty()) {
                $executionId = $dbForProject->getId();
                $execution = $dbForProject->createDocument('executions', new Document([
                    '$id' => $executionId,
                    '$read' => $userId ? ['user:' . $userId] : [],
                    '$write' => [],
                    'dateCreated' => time(),
                    'functionId' => $functionId,
                    'deploymentId' => $deploymentId,
                    'trigger' => $trigger,
                    'status' => 'waiting',
                    'statusCode' => 0,
                    'stdout' => '',
                    'stderr' => '',
                    'time' => 0.0,
                    'search' => implode(' ', [$functionId, $executionId]),
                ]));
                
                if ($execution->isEmpty()) {
                    throw new Exception('Failed to create or read execution');
                }
            }
            $execution->setAttribute('status', 'processing');
            $execution = $dbForProject->updateDocument('executions', $executionId, $execution);
            return $execution;
        });

        /** Collect environment variables */
        $vars = [
            'APPWRITE_FUNCTION_ID' => $functionId,
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deploymentId,
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
            'APPWRITE_FUNCTION_TRIGGER' => $trigger,
            'APPWRITE_FUNCTION_EVENT' => $event,
            'APPWRITE_FUNCTION_EVENT_DATA' => $eventData,
            'APPWRITE_FUNCTION_DATA' => $data,
            'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
            'APPWRITE_FUNCTION_USER_ID' => $userId,
            'APPWRITE_FUNCTION_JWT' => $jwt,
        ];
        $vars = \array_merge($function->getAttribute('vars', []), $vars);

        /** Execute function */
        try {
            $executionResponse = $this->executor->createExecution(
                projectId: $projectId,
                deploymentId: $deploymentId,
                path: $build->getAttribute('outputPath', ''),
                vars: $vars,
                entrypoint: $deployment->getAttribute('entrypoint', ''),
                data: $vars['APPWRITE_FUNCTION_DATA'],
                runtime: $function->getAttribute('runtime', ''),
                timeout: $function->getAttribute('timeout', 0),
                baseImage: $runtime['image']
            );

            /** Update execution status */
            $execution->setAttribute('status', $executionResponse['status']);
            $execution->setAttribute('statusCode', $executionResponse['statusCode']);
            $execution->setAttribute('stdout', $executionResponse['stdout']);
            $execution->setAttribute('stderr', $executionResponse['stderr']);
            $execution->setAttribute('time', $executionResponse['time']);
        } catch (\Throwable $th) {
            $execution->setAttribute('status', 'failed');
            $execution->setAttribute('statusCode', $th->getCode());
            $execution->setAttribute('stderr', $th->getMessage());
            Console::error($th->getMessage());
        }

        $execution = Authorization::skip(fn() => $dbForProject->updateDocument('executions', $executionId, $execution));

        /** Trigger Webhook */
        $executionModel = new Execution();
        $executionUpdate = new Event(Event::WEBHOOK_QUEUE_NAME, Event::WEBHOOK_CLASS_NAME);
        $executionUpdate
            ->setParam('projectId', $projectId)
            ->setParam('userId', $userId)
            ->setParam('webhooks', $webhooks)
            ->setParam('event', 'functions.executions.update')
            ->setParam('eventData', $execution->getArrayCopy(array_keys($executionModel->getRules())));
        $executionUpdate->trigger();

        /** Trigger realtime event */
        $target = Realtime::fromPayload('functions.executions.update', $execution);
        Realtime::send(
            projectId: 'console',
            payload: $execution->getArrayCopy(),
            event: 'functions.executions.update',
            channels: $target['channels'],
            roles: $target['roles']
        );
        Realtime::send(
            projectId: $projectId,
            payload: $execution->getArrayCopy(),
            event: 'functions.executions.update',
            channels: $target['channels'],
            roles: $target['roles']
        );

        /** Update usage stats */
        global $register;
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') === 'enabled') {
            $statsd = $register->get('statsd');
            $usage = new Stats($statsd);
            $usage
                ->setParam('projectId', $projectId)
                ->setParam('functionId', $function->getId())
                ->setParam('functionExecution', 1)
                ->setParam('functionStatus', $execution->getAttribute('status', ''))
                ->setParam('functionExecutionTime', $execution->getAttribute('time') * 1000) // ms
                ->setParam('networkRequestSize', 0)
                ->setParam('networkResponseSize', 0)
                ->submit();
            $usage->submit();
        }
    }

    public function shutdown(): void
    {
    }
}
