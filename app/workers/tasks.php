<?php

require_once __DIR__.'/../init.php';

use Database\Database;
use Database\Validator\Authorization;
use Cron\CronExpression;

cli_set_process_title('Tasks V1 Worker');

echo APP_NAME.' tasks worker v1 has started';

class TasksV1
{
    /**
     * @var array
     */
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $consoleDB, $register, $version;

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

        $taskId = (isset($this->args['$uid'])) ? $this->args['$uid'] : null;
        $updated = (isset($this->args['updated'])) ? $this->args['updated'] : null;
        $next = (isset($this->args['next'])) ? $this->args['next'] : null;
        $delay = time() - $next;
        $errors = [];
        $timeout = 60 * 5; // 5 minutes

        if (empty($taskId)) {
            throw new Exception('Missing task $id');
        }

        Authorization::disable();

        $task = $consoleDB->getDocument($taskId);

        Authorization::enable();

        if (is_null($task->getUid()) || Database::SYSTEM_COLLECTION_TASKS !== $task->getCollection()) {
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
        $headers = (is_array($task->getAttribute('httpHeaders', []))) ? $task->getAttribute('httpHeaders', []) : [];

        $task
            ->setAttribute('next', $next)
            ->setAttribute('previous', time())
        ;

        ResqueScheduler::enqueueAt($next, 'v1-tasks', 'TasksV1', $task->getArrayCopy());

        $startTime = microtime(true);

        // Execute Task

        $ch = curl_init($task->getAttribute('httpUrl'));

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $task->getAttribute('httpMethod'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, sprintf(APP_USERAGENT, $version));
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array_merge($headers, [
                'X-'.APP_NAME.'-Task-UID: '.$task->getAttribute('$uid', ''),
                'X-'.APP_NAME.'-Task-Name: '.$task->getAttribute('name', ''),
            ])
        );
        curl_setopt($ch, CURLOPT_HEADER, true);  // we want headers
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        if (!$task->getAttribute('security', true)) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $httpUser = $task->getAttribute('httpUser');
        $httpPass = $task->getAttribute('httpPass');

        if (!empty($httpUser) && !empty($httpPass)) {
            curl_setopt($ch, CURLOPT_USERPWD, "$httpUser:$httpPass");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $response = curl_exec($ch);

        if (false === $response) {
            $errors[] = curl_error($ch).'Failed to execute task';
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $codeFamily = mb_substr($code, 0, 1);
        $headersSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headersSize);
        $body = substr($response, $headersSize);

        curl_close($ch);

        $totalTime = round(microtime(true) - $startTime, 2);

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
                ->setAttribute('status', ($task->getAttribute('failures') >= 5) ? 'pause' : 'play')
            ;

            $alert = 'Task "'.$task->getAttribute('name').'" failed to execute with the following errors: '.implode($errors, "\n");
        }

        $log = json_decode($task->getAttribute('log', '{}'), true);

        if (count($log) >= 5) {
            array_pop($log);
        }

        array_unshift($log, [
            'code' => $code,
            'duration' => $totalTime,
            'delay' => $delay,
            'errors' => $errors,
            'headers' => $headers,
            'body' => $body,
        ]);

        $task
            ->setAttribute('log', json_encode($log))
            ->setAttribute('duration', $totalTime)
            ->setAttribute('delay', $delay)
        ;

        Authorization::disable();

        if (false === $consoleDB->updateDocument($task->getArrayCopy())) {
            throw new Exception('Failed saving tasks to DB');
        }

        Authorization::enable();

        return true;
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}
