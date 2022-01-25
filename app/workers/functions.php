<?php

use Appwrite\Resque\Worker;
use Cron\CronExpression;
use Swoole\Runtime;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;

require_once __DIR__.'/../init.php';

Runtime::enableCoroutine(0);

Console::title('Functions V1 Worker');
Console::success(APP_NAME . ' functions worker v1 has started');

$runtimes = Config::getParam('runtimes');

$dockerUser = App::getEnv('DOCKERHUB_PULL_USERNAME', null);
$dockerPass = App::getEnv('DOCKERHUB_PULL_PASSWORD', null);
$dockerEmail = App::getEnv('DOCKERHUB_PULL_EMAIL', null);
$orchestration = new Orchestration(new DockerAPI($dockerUser, $dockerPass, $dockerEmail));

$warmupEnd = \microtime(true);
$warmupTime = $warmupEnd - $warmupStart;

Console::success('Finished warmup in ' . $warmupTime . ' seconds');

class FunctionsV1 extends Worker
{
    public array $args = [];

    public array $allowed = [];

    public function getName(): string {
        return "functions";
    }

    public function init(): void
    {
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

                    Authorization::disable();

                    $functions = $database->find('functions', [], $limit, $offset, ['name'], [Database::ORDER_ASC]);

                    Authorization::reset();

                    $sum = \count($functions);
                    $offset = $offset + $limit;

                    Console::log('Fetched ' . $sum . ' functions...');

                    foreach ($functions as $function) {
                        $events =  $function->getAttribute('events', []);
                        $tag =  $function->getAttribute('tag', []);

                        Console::success('Itterating function: ' . $function->getAttribute('name'));

                        if (!\in_array($event, $events) || empty($tag)) {
                            continue;
                        }

                        Console::success('Triggered function: ' . $event);

                        $this->execute(
                            trigger: 'event',
                            projectId: $projectId,
                            executionId: '',
                            database: $database,
                            function: $function,
                            event: $event,
                            eventData: $eventData,
                            data: $data,
                            webhooks: $webhooks,
                            userId: $userId,
                            jwt: $jwt
                        );
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
                Authorization::disable();
                $function = $database->getDocument('functions', $functionId);
                Authorization::reset();

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

                Authorization::disable();

                $function = $database->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
                    'scheduleNext' => (int)$next,
                ])));

                if ($function === false) {
                    throw new Exception('Function update failed (' . $functionId . ')');
                }

                Authorization::reset();

                ResqueScheduler::enqueueAt($next, 'v1-functions', 'FunctionsV1', [
                    'projectId' => $projectId,
                    'webhooks' => $webhooks,
                    'functionId' => $function->getId(),
                    'userId' => $userId,
                    'executionId' => null,
                    'trigger' => 'schedule',
                    'scheduleOriginal' => $function->getAttribute('schedule', ''),
                ]);  // Async task reschedule

                $this->execute(
                    trigger: $trigger,
                    projectId: $projectId,
                    executionId: $executionId,
                    database: $database,
                    function: $function,
                    data: $data,
                    webhooks: $webhooks,
                    userId: $userId,
                    jwt: $jwt
                );
                break;

            case 'http':
                Authorization::disable();
                $function = $database->getDocument('functions', $functionId);
                Authorization::reset();

                if (empty($function->getId())) {
                    throw new Exception('Function not found ('.$functionId.')');
                }

                $this->execute(
                    trigger: $trigger,
                    projectId: $projectId,
                    executionId: $executionId,
                    database: $database,
                    function: $function,
                    data: $data,
                    webhooks: $webhooks,
                    userId: $userId,
                    jwt: $jwt
                );
                break;
        }
    }

    /**
     * Execute function tag
     * 
     * @param string $trigger
     * @param string $projectId
     * @param string $executionId
     * @param Database $database
     * @param Document $function
     * @param string $event
     * @param string $eventData
     * @param string $data
     * @param array $webhooks
     * @param string $userId
     * @param string $jwt
     * 
     * @return void
     */
    public function execute(string $trigger, string $projectId, string $executionId, Database $database, Document $function, string $event = '', string $eventData = '', string $data = '', array $webhooks = [], string $userId = '', string $jwt = ''): void
    {
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, "http://appwrite-executor/v1/functions/{$function->getId()}/executions");
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'trigger' => $trigger,
            'projectId' => $projectId,
            'executionId' => $executionId,
            'event' => $event,
            'eventData' => $eventData,
            'data' => $data,
            'webhooks' => $webhooks,
            'userId' => $userId,
            'jwt' => $jwt,
        ]));
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900) + 200); // + 200 for safety margin
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-appwrite-project: '.$projectId,
            'x-appwrite-executor-key: '. App::getEnv('_APP_EXECUTOR_SECRET', '')
        ]);

        \curl_exec($ch);

        $error = \curl_error($ch);
        if (!empty($error)) {
            Console::error('Curl error: '.$error);
        }

        \curl_close($ch);
    }

    public function shutdown(): void
    {
    }
}
