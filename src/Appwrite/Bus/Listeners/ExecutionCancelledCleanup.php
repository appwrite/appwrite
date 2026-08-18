<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\Events\ExecutionCancelled;
use Appwrite\Event\Message\ExecutionCancelled as ExecutionCancelledMessage;
use Appwrite\Event\Publisher\Execution as ExecutionPublisher;
use Utopia\Bus\Listener;
use Utopia\Database\Document;
use Utopia\Span\Span;

class ExecutionCancelledCleanup extends Listener
{
    public static function getName(): string
    {
        return 'executionCancelledCleanup';
    }

    public static function getEvents(): array
    {
        return [
            ExecutionCancelled::class,
        ];
    }

    public function __construct()
    {
        $this
            ->desc('Removes cancelled execution documents via queue')
            ->inject('publisherForExecutions')
            ->callback($this->handle(...));
    }

    public function handle(ExecutionCancelled $event, ExecutionPublisher $publisherForExecutions): void
    {
        $project = new Document($event->project);
        $execution = new Document($event->execution);

        Span::add('project.id', $project->getId());
        Span::add('function.id', $execution->getAttribute('resourceId', ''));
        Span::add('execution.id', $execution->getId());

        $publisherForExecutions->enqueue(new ExecutionCancelledMessage(
            project: $project,
            execution: $execution,
        ));
    }
}
