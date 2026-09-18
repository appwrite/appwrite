<?php

declare(strict_types=1);

namespace Utopia\Queue\Consumer;

use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * Marks a consumer that can claim several messages in one round trip.
 *
 * A single {@see \Utopia\Queue\Consumer::receive()} costs at least one round
 * trip per message, and on a queue whose handler is cheap that is most of what
 * a worker does. Claiming a batch amortises it, at the price of holding more
 * than one message at a time -- which is why the caller, not the broker,
 * decides how many: the adapter asks for exactly as many as it has free
 * handler slots, so nothing is claimed that cannot be started.
 */
interface Batched
{
    /**
     * Block up to $timeout seconds for the first message, then claim up to
     * $max - 1 more that are already waiting.
     *
     * Three things an implementation must get right, each of which is a bug
     * rather than a quality-of-implementation choice:
     *
     * - Only the first message may block. Waiting for the batch to fill turns a
     *   sparse queue into one whose every message is delivered $timeout late.
     * - Every message returned is claimed, and is owed its own
     *   {@see \Utopia\Queue\Consumer::commit()} or
     *   {@see \Utopia\Queue\Consumer::reject()}. There is no batch acknowledgment,
     *   so one poison message cannot take its neighbours with it.
     * - Nothing may be lost between taking a message off the queue and claiming
     *   it. Where those are two operations, a failure in the second has to put
     *   the batch back.
     *
     * @param int $max At least 1. The caller guarantees it can process every
     *        message returned, concurrently.
     * @return list<Message> Between zero and $max messages; empty on timeout.
     */
    public function receiveBatch(Queue $queue, int $timeout, int $max): array;
}
