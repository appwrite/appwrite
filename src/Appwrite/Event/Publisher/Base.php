<?php

namespace Appwrite\Event\Publisher;

use Appwrite\Event\Message\Base as BaseMessage;
use Utopia\Database\Document;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;

readonly class Base
{
    public function __construct(
        protected Publisher $publisher
    ) {
    }

    /**
     * Publish a message to the queue
     */
    public function publish(Queue $queue, BaseMessage $message): string|bool
    {
        $payload = $this->plain($message->toArray());

        return $this->publisher->publish($queue, $payload);
    }

    /**
     * A payload of arrays and scalars, whatever the message handed over.
     *
     * Every consumer of these queues reconstructs from arrays -- `new Document($payload['project'])`
     * and its ninety-odd siblings -- and until now the codec made that true by accident: JSON
     * flattens an object on the way out and hands back an array on the way in, so a Document or a
     * stdClass in a payload was invisible. It is not invisible to a codec that preserves types.
     * igbinary does, and the first attempt at it took worker-webhooks down for hours on a
     * TypeError thrown by every delivery of 701 messages.
     *
     * So the flattening moves here, where it is a property of the payload rather than a side
     * effect of the format. Objects reach this for honest reasons: `Event::preparePayload()` hands
     * over the project and user Documents whole, and a rendered API response carries `new
     * stdClass()` for every empty map, because `{}` is what an empty map has to look like in JSON.
     * Neither is wrong; neither should decide what a handler receives.
     *
     * `getArrayCopy()` is not enough on its own -- it flattens one level, so a Document nested
     * inside a Document survives it.
     */
    private function plain(mixed $value): mixed
    {
        if ($value instanceof Document) {
            return $this->plain($value->getArrayCopy());
        }

        if ($value instanceof \stdClass) {
            // An empty map rendered for JSON. Consumers already receive [] for these,
            // because that is what json_decode(assoc: true) gives back.
            return $this->plain((array) $value);
        }

        if (\is_array($value)) {
            return \array_map($this->plain(...), $value);
        }

        return $value;
    }

    /**
     * Get the size of a queue
     */
    public function getQueueSize(Queue $queue, bool $failed = false): int
    {
        return $this->publisher->getQueueSize($queue, $failed);
    }
}
