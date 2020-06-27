<?php

use Utopia\Audit\Audit;
use Utopia\Audit\Adapters\MySQL as AuditAdapter;
use Utopia\CLI\Console;

require_once __DIR__.'/../init.php';

\cli_set_process_title('Audits V1 Worker');

Console::success(APP_NAME.' audits worker v1 has started');

class AuditsV1
{
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $register;

        $projectId = $this->args['projectId'];
        $userId = $this->args['userId'];
        $event = $this->args['event'];
        $resource = $this->args['resource'];
        $userAgent = $this->args['userAgent'];
        $ip = $this->args['ip'];
        $data = $this->args['data'];
        $pdo = $register->get('db', true);
        
        $adapter = new AuditAdapter($pdo);
        $adapter->setNamespace('app_'.$projectId);

        $audit = new Audit($adapter);

        $audit->log($userId, $event, $resource, $userAgent, $ip, '', $data);
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}
