<?php

namespace Appwrite\Event;

use Utopia\Cache\Cache;
use Utopia\Queue\Message;
use Utopia\Span\Span;

/**
 * Remembers which targets of a fan-out message were handled, so a redelivery
 * of that message handles only the rest.
 *
 * A message that fans one event out to several targets (webhooks, function
 * subscribers) fails as a whole when one target fails, and the broker then
 * delivers all of it again. Record the targets that were handled before
 * failing it, and ask on the redelivery. The record is written only when the
 * message fails and read only on a redelivery, so a message that succeeds
 * never touches the cache.
 */
final class Redelivery
{
    /**
     * Outlasts every broker retry of a message.
     */
    public const TTL = 60 * 60 * 24 * 7;

    private readonly string $eventId;

    private readonly bool $redelivered;

    /**
     * @param string $consumer Keeps two consumers of the same event apart.
     * @param bool $handledWhenUnknown What an unreadable record answers. True skips a target
     *                                 that may not have been handled; false handles again one
     *                                 that may have been. Pick the loss the consumer can afford.
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly string $consumer,
        Message $message,
        private readonly bool $handledWhenUnknown,
    ) {
        $payload = $message->getPayload();

        // A message published before its publisher named events is named by its
        // payload rather than its pid: the Redis broker requeues a retry under a
        // fresh pid, while the payload is carried through unchanged.
        $eventId = $payload['eventId'] ?? '';
        $this->eventId = \is_string($eventId) && $eventId !== ''
            ? $eventId
            : \md5((string) \json_encode($payload));
        $this->redelivered = $message->getAttempts() > 0;
    }

    /**
     * The same on every delivery of this event to this target, and different for any other pair.
     */
    public function id(string $target): string
    {
        return \md5($this->eventId . ':' . $target);
    }

    /**
     * Whether an earlier delivery of this message already handled the target.
     */
    public function wasHandled(string $target): bool
    {
        if (!$this->redelivered) {
            return false;
        }

        try {
            return $this->cache->load($this->key($target), self::TTL) !== false;
        } catch (\Throwable $th) {
            Span::add('redelivery.lookup.error', $th->getMessage());

            return $this->handledWhenUnknown;
        }
    }

    /**
     * Record the targets this delivery handled, before the message is failed.
     *
     * @param list<string> $targets
     * @return bool Whether every target was recorded. A target that was not will be handled
     *              again by the redelivery.
     */
    public function record(array $targets): bool
    {
        $recorded = true;

        foreach ($targets as $target) {
            try {
                if ($this->cache->save($this->key($target), '1', ttl: self::TTL) === false) {
                    Span::add('redelivery.record.error', 'cache refused the write');
                    $recorded = false;
                }
            } catch (\Throwable $th) {
                Span::add('redelivery.record.error', $th->getMessage());
                $recorded = false;
            }
        }

        return $recorded;
    }

    private function key(string $target): string
    {
        return 'redelivery:' . $this->consumer . ':' . $this->id($target);
    }
}
