<?php

namespace Appwrite\Utopia\Database\Hooks;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Lifecycle;

/**
 * Purges the function events cache when functions are created, updated, or deleted.
 *
 * Registered on dbForProject.
 */
class FunctionCache implements Lifecycle
{
    public function __construct(
        private Document $project,
        private Database $database,
    ) {
    }

    public function handle(Event $event, mixed $data): void
    {
        if (!in_array($event, [Event::DocumentCreate, Event::DocumentUpdate, Event::DocumentDelete])) {
            return;
        }

        if (!$data instanceof Document || $data->getCollection() !== 'functions') {
            return;
        }

        if ($this->project->isEmpty() || $this->project->getId() === 'console') {
            return;
        }

        $hostname = $this->database->getAdapter()->getHostname();
        $cacheKey = \sprintf(
            '%s-cache-%s:%s:%s:project:%s:functions:events',
            $this->database->getCacheName(),
            $hostname ?? '',
            $this->database->getNamespace(),
            $this->database->getTenant(),
            $this->project->getId()
        );

        $this->database->getCache()->purge($cacheKey);
    }
}
