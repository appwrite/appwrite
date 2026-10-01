<?php

declare(strict_types=1);

namespace Utopia\Client\Destination;

use Utopia\Client\Destination;

/**
 * Any address, through any proxy. For services whose address the operator configured,
 * never for a URL a user supplied.
 */
final readonly class Anywhere implements Destination
{
    public function allows(string $address): bool
    {
        return true;
    }

    public function permitsProxy(): bool
    {
        return true;
    }
}
