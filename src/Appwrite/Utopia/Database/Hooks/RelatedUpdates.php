<?php

namespace Appwrite\Utopia\Database\Hooks;

use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Lifecycle;
use Utopia\Database\Hook\Named;

/**
 * Records the two-way related documents a document delete changed, which the database reports as
 * document updates once the delete returns.
 *
 * Registered per request on the database the delete runs on; the name replaces the previous request's recorder.
 */
final class RelatedUpdates implements Lifecycle, Named
{
    /** @var list<Document> */
    private array $documents = [];

    private bool $recording = true;

    public function getName(): string
    {
        return 'relationship-delete';
    }

    public function handle(Event $event, mixed $data): void
    {
        if ($this->recording && $event === Event::DocumentUpdate && $data instanceof Document) {
            $this->documents[] = $data;
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
