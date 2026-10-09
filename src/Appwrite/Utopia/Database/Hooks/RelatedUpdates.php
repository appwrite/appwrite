<?php

namespace Appwrite\Utopia\Database\Hooks;

use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Event\Document\Updated;
use Utopia\Database\Event\Domain;
use Utopia\Database\Hook\Lifecycle;
use Utopia\Database\Hook\Named;
use Utopia\Database\Hook\Selective;

/**
 * Records the two-way related documents a document delete changed, which the database reports as
 * document updates once the delete returns.
 *
 * Registered per request on the database the delete runs on; the name replaces the previous request's recorder.
 */
final class RelatedUpdates implements Lifecycle, Named, Selective
{
    /** @var list<Document> */
    private array $documents = [];

    private bool $recording = true;

    #[\Override]
    public function getName(): string
    {
        return 'relationship-delete';
    }

    #[\Override]
    public function handles(Event $event): bool
    {
        return $event === Event::DocumentUpdate;
    }

    #[\Override]
    public function handle(Domain $event): void
    {
        if ($this->recording && $event instanceof Updated) {
            $this->documents[] = $event->document;
        }
    }

    /**
     * @return list<Document>
     */
    public function stop(): array
    {
        $this->recording = false;

        return $this->documents;
    }
}
