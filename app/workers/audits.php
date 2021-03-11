<?php

use Appwrite\Resque\Worker;
use Utopia\Audit\Audit;
use Utopia\Audit\Adapters\MySQL as AuditAdapter;
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

    public function execute(): void
    {
        global $register;

        $projectId = $this->args['projectId'];
        $userId = $this->args['userId'];
        $event = $this->args['event'];
        $resource = $this->args['resource'];
        $userAgent = $this->args['userAgent'];
        $ip = $this->args['ip'];
        $data = $this->args['data'];
        $db = $register->get('db', true);
        
        $adapter = new AuditAdapter($db);
        $adapter->setNamespace('app_'.$projectId);

        $audit = new Audit($adapter);

        $audit->log($userId, $event, $resource, $userAgent, $ip, '', $data);
    }

    public function shutdown(): void
    {
        // ... Remove environment for this job
    }
}
