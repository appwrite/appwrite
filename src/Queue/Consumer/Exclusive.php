<?php

declare(strict_types=1);

namespace Utopia\Queue\Consumer;

/**
 * Marks a consumer that drives one transport and does NOT serialise access to it.
 *
 * One socket with one shared read pump can only be read by one coroutine at a time.
 * A receive loop parks inside a read on it while the coroutines processing earlier
 * messages commit, reject or extend on the same socket, and Swoole refuses the
 * overlap outright:
 *
 *     Swoole\Error: Socket#5 has already been bound to another coroutine#2,
 *     reading of the same socket in coroutine#3 at the same time is not allowed
 *
 * The worker dies on the first overlap. Server::start() reads this marker to refuse
 * the configuration up front instead, so a concurrency setting a serialising worker
 * carries safely does not reach production as a crash loop.
 *
 * No broker in this package carries the marker; it is here for consumers built
 * outside it. The two shipped ones keep their caps by splitting the blocking receive
 * off the commands and locking the commands: Broker\Redis is handed the two
 * connections and wraps one in Connection\Locking, Broker\Nats resolves its own and
 * holds a lock per connection. Doing that is the better answer wherever the transport
 * allows it — a marked consumer can only be scaled with replicas.
 *
 * The marker is a public contract, not only an input to Server::start(), and a caller
 * that would rather degrade than be refused can read it directly:
 *
 *     if ($coroutines > 1 && $consumer instanceof Exclusive) {
 *         $coroutines = 1;  // and say so
 *     }
 *
 * That is worth knowing for a deployment whose concurrency comes from configuration
 * rather than from code, where a refusal at start() is a worker that will not boot.
 * Clamping on the marker also survives the consumer later dropping it — the cap starts
 * applying on the upgrade, with nothing to change at the call site.
 */
interface Exclusive {}
