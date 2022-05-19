<?php

use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Resque\Worker;
use Appwrite\Stats\Stats;
use Appwrite\Utopia\Response\Model\Execution;
use Cron\CronExpression;
use Executor\Executor;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../init.php';

Console::title('Functions V1 Worker');
Console::success(APP_NAME . ' functions worker v1 has started');

class FunctionsV1 extends Worker
{
    private ?Executor $executor = null;
    public array $args = [];
    public array $allowed = [];

    public function getName(): string
    {
        return "functions";
    }

    public function init(): void
    {
        $this->executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));
    }

    public function run(): void
    {
        $type = $this->args['type'] ?? '';
        $events = $this->args['events'] ?? [];
        $project = new Document($this->args['project'] ?? []);
        $user = new Document($this->args['user'] ?? []);
        $payload = json_encode($this->args['payload'] ?? []);

        $database = $this->getProjectDB($project->getId());

        /**
         * Handle Event execution.
         */
        if (!empty($events)) {
            $limit = 30;
            $sum = 30;
            $offset = 0;
            $functions = [];
            /** @var Document[] $functions */

            while ($sum >= $limit) {
                $functions = Authorization::skip(fn () => $database->find('functions', [], $limit, $offset, ['name'], [Database::ORDER_ASC]));
                $sum = \count($functions);
                $offset = $offset + $limit;

                Console::log('Fetched ' . $sum . ' functions...');

                foreach ($functions as $function) {
                    if (!array_intersect($events, $function->getAttribute('events', []))) {
                        continue;
                    }

                    Console::success('Iterating function: ' . $function->getAttribute('name'));

                    $this->execute(
                        project: $project,
                        function: $function,
                        dbForProject: $database,
                        trigger: 'event',
                        // Pass first, most verbose event pattern
                        event: $events[0],
                        eventData: $payload,
                        user: $user
                    );

                    Console::success('Triggered function: ' . $events[0]);
                }
            }

            return;
        }

        /**
         * Handle Schedule and HTTP execution.
         */
        $user = new Document($this->args['user'] ?? []);
        $project = new Document($this->args['project'] ?? []);
        $execution = new Document($this->args['execution'] ?? []);
        $function = new Document($this->args['function'] ?? []);

        switch ($type) {
            case 'http':
                $jwt = $this->args['jwt'] ?? '';
                $data = $this->args['data'] ?? '';

                $function = Authorization::skip(fn () => $database->getDocument('functions', $execution->getAttribute('functionId')));

                $this->execute(
                    project: $project,
                    function: $function,
                    dbForProject: $database,
                    executionId: $execution->getId(),
                    trigger: 'http',
                    data: $data,
                    user: $user,
                    jwt: $jwt
                );

                break;

            case 'schedule':
                $scheduleOriginal = $execution->getAttribute('scheduleOriginal', '');
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
                $function = Authorization::skip(fn () => $database->getDocument('functions', $function->getId()));

                if (empty($function->getId())) {
                    throw new Exception('Function not found (' . $function->getId() . ')');
                }

                if ($scheduleOriginal && $scheduleOriginal !== $function->getAttribute('schedule')) { // Schedule has changed from previous run, ignore this run.
                    return;
                }

                $cron = new CronExpression($function->getAttribute('schedule'));
                $next = (int) $cron->getNextRunDate()->format('U');

                $function
                    ->setAttribute('scheduleNext', $next)
                    ->setAttribute('schedulePrevious', \time());

                $function = Authorization::skip(fn () => $database->updateDocument(
                    'functions',
                    $function->getId(),
                    $function->setAttribute('scheduleNext', (int) $next)
                ));

                if ($function === false) {
                    throw new Exception('Function update failed.');
                }

                $reschedule = new Func();
                $reschedule
                    ->setFunction($function)
                    ->setType('schedule')
                    ->setUser($user)
                    ->setProject($project);

                // Async task reschedule
                $reschedule->schedule($next);

                $this->execute(
                    project: $project,
                    function: $function,
                    dbForProject: $database,
                    trigger: 'schedule'
                );

                break;
        }
    }

    private function execute(
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
    ) {

        $user ??= new Document();
        $functionId = $function->getId();
        $deploymentId = $function->getAttribute('deployment', '');

        /** Check if deployment exists */
        $deployment = Authorization::skip(fn () => $dbForProject->getDocument('deployments', $deploymentId));

        if ($deployment->getAttribute('resourceId') !== $functionId) {
            throw new Exception('Deployment not found. Create deployment before trying to execute a function', 404);
        }

        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found. Create deployment before trying to execute a function', 404);
        }

        /** Check if build has exists */
        $build = Authorization::skip(fn () => $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', '')));
        if ($build->isEmpty()) {
            throw new Exception('Build not found', 404);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Build not ready', 400);
        }

        /** Check if  runtime is supported */
        $runtimes = Config::getParam('runtimes', []);

        if (!\array_key_exists($function->getAttribute('runtime'), $runtimes)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported', 400);
        }

        $runtime = $runtimes[$function->getAttribute('runtime')];

        /** Create execution or update execution status */
        $execution = Authorization::skip(function () use ($dbForProject, &$executionId, $functionId, $deploymentId, $trigger, $user) {
            $execution = $dbForProject->getDocument('executions', $executionId ?? '');
            if ($execution->isEmpty()) {
                $executionId = $dbForProject->getId();
                $execution = $dbForProject->createDocument('executions', new Document([
                    '$id' => $executionId,
                    '$read' => $user->isEmpty() ? [] : ['user:' . $user->getId()],
                    '$write' => [],
                    'dateCreated' => time(),
                    'functionId' => $functionId,
                    'deploymentId' => $deploymentId,
                    'trigger' => $trigger,
                    'status' => 'waiting',
                    'statusCode' => 0,
                    'response' => '',
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
            'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
            'APPWRITE_FUNCTION_USER_ID' => $user->getId(),
            'APPWRITE_FUNCTION_JWT' => $jwt,
        ];
        $vars = \array_merge($function->getAttribute('vars', []), $vars);

        /** Execute function */
        try {
            $executionResponse = $this->executor->createExecution(
                projectId: $project->getId(),
                deploymentId: $deploymentId,
                path: $build->getAttribute('outputPath', ''),
                vars: $vars,
                entrypoint: $deployment->getAttribute('entrypoint', ''),
                data: $vars['APPWRITE_FUNCTION_DATA'] ?? '',
                runtime: $function->getAttribute('runtime', ''),
                timeout: $function->getAttribute('timeout', 0),
                baseImage: $runtime['image']
            );

            /** Update execution status */
            $execution
                ->setAttribute('status', $executionResponse['status'])
                ->setAttribute('statusCode', $executionResponse['statusCode'])
                ->setAttribute('response', $executionResponse['response'])
                ->setAttribute('stderr', $executionResponse['stderr'])
                ->setAttribute('time', $executionResponse['time']);
        } catch (\Throwable $th) {
            $endtime = \microtime(true);
            $time = $endtime - $execution->getAttribute('dateCreated');
            $execution
                ->setAttribute('time', $time)
                ->setAttribute('status', 'failed')
                ->setAttribute('statusCode', $th->getCode())
                ->setAttribute('stderr', $th->getMessage());
            Console::error($th->getMessage());
        }

        $execution = Authorization::skip(fn () => $dbForProject->updateDocument('executions', $executionId, $execution));
        /** @var Document $execution */

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
                ->setParam('functionExecution', 1)
                ->setParam('functionStatus', $execution->getAttribute('status', ''))
                ->setParam('functionExecutionTime', $execution->getAttribute('time') * 1000) // ms
                ->setParam('networkRequestSize', 0)
                ->setParam('networkResponseSize', 0)
                ->submit();
        }
    }

    public function shutdown(): void
    {
    }
}
