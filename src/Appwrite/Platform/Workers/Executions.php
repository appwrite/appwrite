<?php

namespace Appwrite\Platform\Workers;

use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class Executions extends Action
{
    public static function getName(): string
    {
        return 'executions';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Executions worker')
            ->groups(['executions'])
            ->inject('message')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        Message $message,
        Database $dbForProject,
    ): void {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $execution = new Document($payload['execution'] ?? []);

        if ($execution->isEmpty()) {
            throw new Exception('Missing execution');
        }

        $project = new Document($payload['project'] ?? []);
        if ($project->getId() != '6862e6ad0007bea1226e') {
            $dbForProject->upsertDocument('executions', $execution);
        }
    }
}
