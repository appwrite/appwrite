<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\Events\ExecutionCompleted;
use Appwrite\Event\Message\Execution as ExecutionMessage;
use Appwrite\Event\Publisher\Execution as ExecutionPublisher;
use Utopia\Bus\Listener;
use Utopia\Database\Document;
use Utopia\Span\Span;

class Log extends Listener
{
    public static function getName(): string
    {
        return 'log';
    }

    public static function getEvents(): array
    {
        return [ExecutionCompleted::class];
    }

    public function __construct()
    {
        $this
            ->desc('Persists execution logs to database via queue')
            ->inject('publisherForExecutions')
            ->callback($this->handle(...));
    }

    public function handle(ExecutionCompleted $event, ExecutionPublisher $publisherForExecutions): void
    {
        $project = new Document($event->project);
        $execution = new Document($event->execution);

        if ($execution->getAttribute('resourceType', '') === 'functions') {
            Span::add('project.id', $project->getId());
            Span::add('function.id', $execution->getAttribute('resourceId', ''));
            Span::add('execution.id', $execution->getId());
            Span::add('deployment.id', $execution->getAttribute('deploymentId', ''));
            Span::add('execution.status', $execution->getAttribute('status', ''));
        }

        $publisherForExecutions->enqueue(new ExecutionMessage(
            project: $project,
            execution: $execution,
        ));
    }
}
