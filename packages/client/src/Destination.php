<?php

declare(strict_types=1);

namespace Utopia\Client;

/**
 * Where an adapter may send a request. Every adapter is built with one, and checks it
 * against the address its transport actually connected to (every connection, every
 * redirect hop), not a DNS answer taken earlier, so DNS cannot change the answer
 * between a check and the connection.
 */
interface Destination
{
    /**
     * Whether a connection to this IP address may carry the request.
     */
    public function allows(string $address): bool;

    /**
     * Whether the request may go through a proxy. Through a proxy the connected address is
     * the proxy's, so a destination that restricts addresses must not permit one.
     */
    public function permitsProxy(): bool;
}
