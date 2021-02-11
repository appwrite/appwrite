<?php

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Appwrite\Database\Database;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Validator\Authorization;
use Cron\CronExpression;

require_once __DIR__.'/../init.php';

Console::title('Tasks V1 Worker');

Console::success(APP_NAME.' tasks worker v1 has started');

class TasksV1
{
    /**
     * @var array
     */
    public $args = [];

    public function setUp(): void
    {
    }

    public function perform()
    {
        global $register;

        $consoleDB = new Database();
        $consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
        $consoleDB->setNamespace('app_console'); // Main DB
        $consoleDB->setMocks(Config::getParam('collections', []));

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

        $taskId = $this->args['$id'] ?? null;
        $updated = $this->args['updated'] ?? null;
        $next = $this->args['next'] ?? null;
        $delay = \time() - $next;
        $errors = [];
        $timeout = 60 * 5; // 5 minutes
        $errorLimit = 5;
        $logLimit = 5;
        $alert = '';

        if (empty($taskId)) {
            throw new Exception('Missing task $id');
        }

        Authorization::disable();

        $task = $consoleDB->getDocument($taskId);

        Authorization::reset();

        if (\is_null($task->getId()) || Database::SYSTEM_COLLECTION_TASKS !== $task->getCollection()) {
            throw new Exception('Task Not Found');
        }

        if ($task->getAttribute('updated') !== $updated) { // Task have already been rescheduled by owner
            return false;
        }

        if ($task->getAttribute('status') !== 'play') { // Skip task and don't schedule again
            return false;
        }

        // Reschedule

        $cron = CronExpression::factory($task->getAttribute('schedule'));
        $next = (int) $cron->getNextRunDate()->format('U');
        $headers = (\is_array($task->getAttribute('httpHeaders', []))) ? $task->getAttribute('httpHeaders', []) : [];

        $task
            ->setAttribute('next', $next)
            ->setAttribute('previous', \time())
        ;

        ResqueScheduler::enqueueAt($next, 'v1-tasks', 'TasksV1', $task->getArrayCopy());  // Async task rescheduale

        $startTime = \microtime(true);

        // Execute Task

        $ch = \curl_init($task->getAttribute('httpUrl'));

        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $task->getAttribute('httpMethod'));
        \curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        \curl_setopt($ch, CURLOPT_HEADER, 0);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_USERAGENT, \sprintf(APP_USERAGENT,
            App::getEnv('_APP_VERSION', 'UNKNOWN'),
            App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY)
        ));
        \curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            \array_merge($headers, [
                'X-'.APP_NAME.'-Task-ID: '.$task->getAttribute('$id', ''),
                'X-'.APP_NAME.'-Task-Name: '.$task->getAttribute('name', ''),
            ])
        );
        \curl_setopt($ch, CURLOPT_HEADER, true);  // we want headers
        \curl_setopt($ch, CURLOPT_NOBODY, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        if (!$task->getAttribute('security', true)) {
            \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $httpUser = $task->getAttribute('httpUser');
        $httpPass = $task->getAttribute('httpPass');

        if (!empty($httpUser) && !empty($httpPass)) {
            \curl_setopt($ch, CURLOPT_USERPWD, "$httpUser:$httpPass");
            \curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $response = \curl_exec($ch);

        if (false === $response) {
            $errors[] = \curl_error($ch).'Failed to execute task';
        }

        $code = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $codeFamily = \mb_substr($code, 0, 1);
        $headersSize = \curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = \substr($response, 0, $headersSize);
        $body = \substr($response, $headersSize);

        \curl_close($ch);

        $totalTime = \round(\microtime(true) - $startTime, 2);

        switch ($codeFamily) {
            case '2':
            case '3':
            break;
            default:
                $errors[] = 'Request failed with status code '.$code;
        }

        if (empty($errors)) {
            $task->setAttribute('failures', 0);

            $alert = 'Task "'.$task->getAttribute('name').'" Executed Successfully';
        } else {
            $task
                ->setAttribute('failures', $task->getAttribute('failures', 0) + 1)
                ->setAttribute('status', ($task->getAttribute('failures') >= $errorLimit) ? 'pause' : 'play')
            ;

            $alert = 'Task "'.$task->getAttribute('name').'" failed to execute with the following errors: '.\implode("\n", $errors);
        }

        $log = \json_decode($task->getAttribute('log', '{}'), true);

        if (\count($log) >= $logLimit) {
            \array_pop($log);
        }

        \array_unshift($log, [
            'code' => $code,
            'duration' => $totalTime,
            'delay' => $delay,
            'errors' => $errors,
            'headers' => $headers,
            'body' => $body,
        ]);

        $task
            ->setAttribute('log', \json_encode($log))
            ->setAttribute('duration', $totalTime)
            ->setAttribute('delay', $delay)
        ;

        Authorization::disable();

        if (false === $consoleDB->updateDocument($task->getArrayCopy())) {
            throw new Exception('Failed saving tasks to DB');
        }

        Authorization::reset();

        // ResqueScheduler::enqueueAt($next, 'v1-tasks', 'TasksV1', $task->getArrayCopy());  // Sync task rescheduale

        // Send alert if needed (use SMTP as default for now)

        return true;
    }

    public function tearDown(): void
    {
        // ... Remove environment for this job
    }
}
