<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\Events\ExecutionCompleted;
use Appwrite\Event\Message\Execution as ExecutionMessage;
use Appwrite\Event\Publisher\Execution as ExecutionPublisher;
use Utopia\Bus\Listener;
use Utopia\Database\Document;
use Utopia\Span\Span;
use Utopia\System\System;

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
            $traceProjectId = System::getEnv('_APP_TRACE_PROJECT_ID', '');
            $traceFunctionId = System::getEnv('_APP_TRACE_FUNCTION_ID', '');
            $resourceId = $execution->getAttribute('resourceId', '');
            if ($traceProjectId !== '' && $traceFunctionId !== '' && $project->getId() === $traceProjectId && $resourceId === $traceFunctionId) {
                Span::init('execution.trace.v1_executions_enqueue');
                Span::add('datetime', gmdate('c'));
                Span::add('projectId', $project->getId());
                Span::add('functionId', $resourceId);
                Span::add('executionId', $execution->getId());
                Span::add('deploymentId', $execution->getAttribute('deploymentId', ''));
                Span::add('status', $execution->getAttribute('status', ''));
                Span::current()?->finish();
            }
        }

        $publisherForExecutions->enqueue(new ExecutionMessage(
            project: $project,
            execution: $execution,
        ));
    }
}
