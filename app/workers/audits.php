<?php

use Appwrite\Resque\Worker;
use Utopia\Audit\Audit;
use Utopia\CLI\Console;
use Utopia\Database\Document;

require_once __DIR__ . '/../init.php';

Console::title('Audits V1 Worker');
Console::success(APP_NAME . ' audits worker v1 has started');

class AuditsV1 extends Worker
{
    public function getName(): string
    {
        return "audits";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $event = $this->args['event'];
        $payload = $this->args['payload'];
        $mode = $this->args['mode'];
        $resource = $this->args['resource'];
        $userAgent = $this->args['userAgent'];
        $ip = $this->args['ip'];

        $user = new Document($this->args['user']);
        $project = new Document($this->args['project']);

        $userName = $user->getAttribute('name', '');
        $userEmail = $user->getAttribute('email', '');

        $dbForProject = $this->getProjectDB($project);
        $audit = new Audit($dbForProject);
        $audit->log(
            userInternalId: $user->getInternalId(),
            userId: $user->getId(),
            // Pass first, most verbose event pattern
            event: $event,
            resource: $resource,
            userAgent: $userAgent,
            ip: $ip,
            location: '',
            data: [
                'userName' => $userName,
                'userEmail' => $userEmail,
                'mode' => $mode,
                'data' => $payload,
            ]
        );
    }

    public function shutdown(): void
    {
    }
}
