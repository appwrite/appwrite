<?php

use Appwrite\Resque\Worker;
use Utopia\Audit\Audit;
use Utopia\CLI\Console;

require_once __DIR__.'/../init.php';

Console::title('Audits V1 Worker');
Console::success(APP_NAME.' audits worker v1 has started');

class AuditsV1 extends Worker
{
    public $args = [];

    public function init(): void
    {
    }

    public function run(): void
    {
        $projectId = $this->args['projectId'];
        $userId = $this->args['userId'];
        $userName = $this->args['userName'];
        $userEmail = $this->args['userEmail'];
        $mode = $this->args['mode'];
        $event = $this->args['event'];
        $resource = $this->args['resource'];
        $userAgent = $this->args['userAgent'];
        $ip = $this->args['ip'];
        $data = $this->args['data'];
        
        $dbForInternal = $this->getInternalDB($projectId);
        $audit = new Audit($dbForInternal);

        $audit->log($userId, $event, $resource, $userAgent, $ip, '', [
            'userName' => $userName,
            'userEmail' => $userEmail,
            'mode' => $mode,
            'data' => $data,
        ]);
    }

    public function shutdown(): void
    {
        // ... Remove environment for this job
    }
}