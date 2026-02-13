<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\ExecutionCompleted;
use Appwrite\Event\Execution;
use Utopia\Bus\Listener;
use Utopia\Database\Document;
use Utopia\Queue\Publisher;

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
            ->inject('publisher')
            ->callback($this->handle(...));
    }

    public function handle(ExecutionCompleted $event, Publisher $publisher): void
    {
        $queueForExecutions = new Execution($publisher);
        $queueForExecutions
            ->setExecution(new Document($event->execution))
            ->setProject(new Document($event->project))
            ->trigger();
    }
}
