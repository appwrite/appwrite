<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\Events\ExecutionCompleted;
use Appwrite\Bus\Events\ExecutionScheduled;
use Appwrite\Event\Message\Execution as ExecutionMessage;
use Appwrite\Event\Publisher\Execution as ExecutionPublisher;
use Appwrite\Execution\Store;
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
        return [
            ExecutionCompleted::class,
            ExecutionScheduled::class,
        ];
    }

    public function __construct()
    {
        $this
            ->desc('Persists execution logs to ClickHouse')
            ->inject('publisherForExecutions')
            ->inject('executionStore')
            ->callback($this->handle(...));
    }

    public function handle(
        ExecutionCompleted|ExecutionScheduled $event,
        ExecutionPublisher $publisherForExecutions,
        Store $executionStore,
    ): void {
        $project = new Document($event->project);
        $execution = new Document($event->execution);

        if ($execution->getAttribute('resourceType', '') === 'functions') {
            Span::add('project.id', $project->getId());
            Span::add('function.id', $execution->getAttribute('resourceId', ''));
            Span::add('execution.id', $execution->getId());
            Span::add('deployment.id', $execution->getAttribute('deploymentId', ''));
            Span::add('execution.status', $execution->getAttribute('status', ''));
        }

        // The API returns the execution ID, so its first snapshot is stored before the response.
        if ($event instanceof ExecutionScheduled) {
            try {
                $executionStore->upsert($project->getId(), $execution);
                return;
            } catch (\Throwable $th) {
                Span::add('execution.store.error', $th->getMessage());
            }
        }

        $publisherForExecutions->enqueue(new ExecutionMessage(
            project: $project,
            execution: $execution,
        ));
    }
}
