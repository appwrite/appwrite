<?php

namespace Appwrite\Bus\Events;

use Utopia\Bus\Event;
use Utopia\Database\Document;

/**
 * Base class for resource lifecycle events (create/update/delete/...).
 *
 * Each concrete resource+operation defines its own subclass (e.g. {@see TeamCreated})
 * that fills in the event-name template, params, the raw resource document and its
 * response model. Cross-cutting outbound listeners (realtime, functions, webhooks)
 * subscribe to this base type and therefore receive every subtype; domain-specific
 * listeners can subscribe to a concrete subclass.
 *
 * The event carries the *raw* document plus its model name rather than a pre-filtered
 * payload: each consumer owns how it projects the resource for its own channel. It also
 * carries the string event name + params because realtime channel routing and
 * function/webhook subscription matching are all driven by event strings.
 */
abstract class ResourceEvent implements Event
{
    /**
     * @param string $event                    event name template, e.g. "teams.[teamId].create"
     * @param array<string, string> $params    placeholder values, e.g. ['teamId' => '...']
     * @param Document $document                the raw resource document
     * @param string $model                     response model used to project the document
     * @param array<int, string> $sensitive    payload keys to omit from broadcasts
     * @param array<string, Document> $context  database/collection/table/bucket for channel routing
     * @param array<int, string> $subscribers   explicit project subscribers (worker-originated events)
     */
    public function __construct(
        public readonly string $event,
        public readonly array $params,
        public readonly Document $document,
        public readonly string $model,
        public readonly ?Document $project = null,
        public readonly ?Document $user = null,
        public readonly ?string $userId = null,
        public readonly array $sensitive = [],
        public readonly array $context = [],
        public readonly array $subscribers = [],
    ) {
    }
}
