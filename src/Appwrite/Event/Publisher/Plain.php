<?php

namespace Appwrite\Event\Publisher;

use Utopia\Database\Document;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

/**
 * A publisher that hands the broker arrays and scalars, whatever it was given.
 *
 * Every consumer of these queues rebuilds from arrays -- `new Document($payload['project'])` and
 * around ninety siblings -- and until now the codec made that true by accident: JSON flattens an
 * object on the way out and returns an array on the way in, so a Document or a stdClass inside a
 * payload was invisible. It is not invisible to a codec that preserves types. igbinary does, and
 * the first attempt at it took worker-webhooks down for hours on a TypeError thrown by every
 * delivery of 701 messages.
 *
 * Objects reach a payload for honest reasons -- {@see \Appwrite\Event\Event::preparePayload()}
 * hands over the project and user Documents whole, and a rendered API response carries
 * `new stdClass()` for every empty map because that is what `{}` has to be in JSON. Neither is
 * wrong; neither should decide what a handler receives.
 *
 * It wraps the publisher rather than any one caller because a payload reaches the broker by more
 * than one route: `Event::trigger()` publishes directly, typed messages go through
 * {@see Base::publish()}, and workers publish from their own resources. All of them resolve the
 * same `publisher` resource, so this is the one point they share.
 */
readonly class Plain implements Synchronous
{
    public function __construct(private Synchronous $publisher)
    {
    }

    public function publish(Queue $queue, array $payload): bool
    {
        return $this->publisher->publish($queue, $this->plain($payload));
    }

    public function publishMany(Queue $queue, array $payloads): bool
    {
        return $this->publisher->publishMany($queue, $this->plain($payloads));
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
        $this->publisher->retry($queue, $limit);
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        return $this->publisher->getQueueSize($queue, $failedJobs);
    }

    /**
     * Arrays and scalars, all the way down.
     *
     * Deliberately narrow: Document and stdClass are the two an audit of a live fleet found in
     * payloads. Any other object class passes through, where it is worth looking at rather than
     * silently reshaping.
     *
     * getArrayCopy() alone would not do it -- that flattens one level, so a Document nested inside
     * a Document survives it.
     */
    private function plain(mixed $value): mixed
    {
        if ($value instanceof Document) {
            return $this->plain($value->getArrayCopy());
        }

        if ($value instanceof \stdClass) {
            // An empty map rendered for JSON. Consumers already receive [] for these, because
            // that is what json_decode(assoc: true) gives back.
            return $this->plain((array) $value);
        }

        if (\is_array($value)) {
            return \array_map($this->plain(...), $value);
        }

        return $value;
    }
}
