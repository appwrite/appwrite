<?php

declare(strict_types=1);

namespace Utopia\Queue\Consumer;

/**
 * Marks a consumer whose broker caps how many messages it may hold unacked.
 *
 * The ceiling is per consumer, not per process: replicas of a worker share one,
 * so scaling out does not widen it. What fills it is not only work in progress.
 * JetStream counts a message parked in NAK backoff as in flight — it is asleep,
 * waiting for its next attempt, and it holds a delivery slot for every second of
 * that. So the ceiling has to cover the handlers *and* whatever is sleeping
 * behind them, and sizing it at the handler count alone guarantees the case it
 * was meant to prevent: as many failures as there are handlers, and the consumer
 * has no slot left to deliver into. It stops handing out work it could have run,
 * which reads from outside as a queue that is backed up for no reason.
 *
 * {@see \Utopia\Queue\Server::start()} reads this to refuse a coroutine cap at or
 * above the ceiling, the way {@see Exclusive} refuses a cap above one — a
 * configuration whose failure mode is a wedged queue, caught at boot rather than
 * found in a backlog graph. Null means the broker is unbounded (or declines to
 * say), and nothing is refused.
 *
 * Declaring a ceiling is not a claim that the queue can survive a poison message
 * on its own: {@see \Utopia\Queue\PermanentFailure} is what keeps one from
 * occupying a slot for its whole redelivery budget.
 */
interface Bounded
{
    /**
     * Most messages this consumer may hold unacknowledged at once, or null when
     * the broker sets no ceiling of its own.
     */
    public function inFlightCeiling(): ?int;
}
