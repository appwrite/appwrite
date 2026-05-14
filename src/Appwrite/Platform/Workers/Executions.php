<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Execution;
use Exception;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\Span\Span;

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
        $executionMessage = Execution::fromArray($message->getPayload());
        $execution = $executionMessage->execution;

        if ($execution->isEmpty()) {
            throw new Exception('Missing execution');
        }

        Span::add('project.id', $executionMessage->project->getId());
        Span::add('function.id', $execution->getAttribute('resourceId', ''));
        Span::add('execution.id', $execution->getId());
        Span::add('deployment.id', $execution->getAttribute('deploymentId', ''));
        Span::add('resource.type', $execution->getAttribute('resourceType', ''));

        $dbForProject->upsertDocument('executions', $execution);
    }
}
