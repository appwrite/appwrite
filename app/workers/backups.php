<?php

use Appwrite\Event\Event;
use Appwrite\Resque\Worker;
use Utopia\Audit\Audit;
use Utopia\CLI\Console;
use Utopia\Database\Document;

require_once __DIR__ . '/../init.php';

Console::title('Backups V1 Worker');
Console::success(APP_NAME . ' backups worker v1 has started');

class BackupsV1 extends Worker
{
    public function getName(): string
    {
        return "backups";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $context = $this->args['context'];
        $project = $this->args['project'];
        $backupId = $this->args['payload']['backupId'];

        /** Create a database dump of all the tables in this project database */
        $dbForProject = $this->getProjectDB($project->getId());

    }

    public function shutdown(): void
    {
    }
}
