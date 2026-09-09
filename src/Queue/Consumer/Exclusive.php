<?php

declare(strict_types=1);

namespace Utopia\Queue\Consumer;

/**
 * Marks a consumer whose transport may only be driven by one coroutine at a time.
 *
 * A NATS connection is one socket with one shared read pump. The receive loop parks
 * inside a read on that socket while the coroutines processing earlier messages
 * commit, reject or extend on the same socket, and Swoole refuses the overlap
 * outright:
 *
 *     Swoole\Error: Socket#5 has already been bound to another coroutine#2,
 *     reading of the same socket in coroutine#3 at the same time is not allowed
 *
 * The worker dies on the first overlap. Server::start() reads this marker to refuse
 * the configuration up front instead, so a concurrency setting that a Redis worker
 * carries safely does not reach production as a crash loop on NATS.
 *
 * Redis does not carry the marker: Connection\Locking serialises concurrent
 * coroutines onto a shared connection, so its consumers stay safe above one.
 */
interface Exclusive {}
