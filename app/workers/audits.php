<?php

use Appwrite\Event\Event;
use Appwrite\Resque\Worker;
use Utopia\Audit\Audit;
use Utopia\CLI\Console;
use Utopia\Database\Document;

require_once __DIR__.'/../init.php';

Console::title('Audits V1 Worker');
Console::success(APP_NAME . ' audits worker v1 has started');

class AuditsV1 extends Worker
{
    public function getName(): string {
        return "audits";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $events = $this->args['events'];
        $user = new Document($this->args['user']);
        $project = new Document($this->args['project']);
        $payload = $this->args['payload'];

        $userName = $user->getAttribute('name', '');
        $userEmail = $user->getAttribute('email', '');

        $event = $events[0];
        $mode = $payload['mode'];
        $resource = $payload['resource'];
        $userAgent = $payload['userAgent'];
        $ip = $payload['ip'];
        $data = $payload['data'];

        $dbForProject = $this->getProjectDB($project->getId());
        $audit = new Audit($dbForProject);
        $audit->log($user->getId(), $event, $resource, $userAgent, $ip, '', [
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
