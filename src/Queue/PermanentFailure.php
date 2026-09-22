<?php

declare(strict_types=1);

namespace Utopia\Queue;

/**
 * A failure that running the job again cannot fix.
 *
 * Redelivery is the right answer to a timeout, a leader election or a restart —
 * the work did not happen, and it will next time. It is the wrong answer to a
 * credential the server rejected, a tenant whose table does not exist, or a
 * payload naming a region that is not configured: every attempt fails the same
 * way, and on JetStream each of them holds one of the consumer's maxAckPending
 * slots for the whole of its backoff. Enough of those and the consumer has no
 * slot left to deliver into, so a queue stops moving because of messages that
 * were never going to succeed.
 *
 * Throwing this from a handler ends the message's life at the first attempt on a
 * broker that charges for attempts: JetStream dead-letters it immediately and the
 * ack slot comes back in milliseconds instead of minutes. Redis has no such
 * window, so the message stays on the failed list its sweep reads -- nothing
 * re-runs it there either, and that is the list an operator can still recover
 * from once the underlying fault is fixed.
 *
 *     throw new PermanentFailure("Region hostname not configured: {$region}");
 *
 * Wrap the original where there is one, so the trace survives the verdict:
 *
 *     catch (PulseRejected $error) {
 *         throw new PermanentFailure('Pulse rejected the ingestion signature', previous: $error);
 *     }
 *
 * A handler that cannot reach the throw site — a failure classified by an error
 * hook, or an exception type owned by someone else — marks the message instead
 * and rethrows whatever it already had: {@see Message::terminal()}.
 *
 * Only for failures that are permanent for this payload. A fault that is
 * permanent right now but transient in principle — a database that is down, a
 * dependency mid-deploy — is exactly what the redelivery budget is for, and
 * dead-lettering it converts an outage into lost work.
 */
class PermanentFailure extends \RuntimeException {}
