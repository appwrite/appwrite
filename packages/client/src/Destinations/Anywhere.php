<?php

declare(strict_types=1);

namespace Utopia\Client\Destinations;

use Utopia\Client\Destinations;

/**
 * Any address, through any proxy. For services whose address the operator configured,
 * never for a URL a user supplied.
 */
final readonly class Anywhere implements Destinations
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
