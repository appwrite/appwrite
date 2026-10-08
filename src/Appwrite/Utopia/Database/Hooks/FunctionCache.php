<?php

namespace Appwrite\Utopia\Database\Hooks;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Event\Document\Created;
use Utopia\Database\Event\Document\Deleted;
use Utopia\Database\Event\Document\Updated;
use Utopia\Database\Event\Domain;
use Utopia\Database\Hook\Lifecycle;
use Utopia\Database\Hook\Selective;

/**
 * Purges the function events cache when functions are created, updated, or deleted.
 *
 * Registered on dbForProject.
 */
class FunctionCache implements Lifecycle, Selective
{
    public function __construct(
        private Document $project,
        private Database $database,
    ) {
    }

    #[\Override]
    public function handles(Event $event): bool
    {
        return \in_array($event, [Event::DocumentCreate, Event::DocumentUpdate, Event::DocumentDelete], true);
    }

    #[\Override]
    public function handle(Domain $event): void
    {
        if (!($event instanceof Created || $event instanceof Updated || $event instanceof Deleted)) {
            return;
        }

        if ($event->collection !== 'functions') {
            return;
        }

        if ($this->project->isEmpty() || $this->project->getId() === 'console') {
            return;
        }

        $cacheKey = \sprintf(
            '%s-cache-%s:%s:%s:project:%s:functions:events',
            $this->database->getCacheName(),
            $this->database->getHostname() ?? '',
            $this->database->getNamespace(),
            $this->database->getTenant(),
            $this->project->getId()
        );

        $this->database->getCache()->purge($cacheKey);
    }
}
