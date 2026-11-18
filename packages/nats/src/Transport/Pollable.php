<?php

declare(strict_types=1);

namespace Utopia\NATS\Transport;

/**
 * A transport that can tell, without waiting and without consuming anything,
 * whether a read would return at once: data has arrived or the peer has closed.
 */
interface Pollable
{
    public function poll(): bool;
}
