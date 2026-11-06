<?php

declare(strict_types=1);

namespace Utopia\Cache\Exception;

/**
 * The cache could not confirm a purge: the circuit is open, or the adapter failed it. The entry may still be cached.
 */
class Unavailable extends \RuntimeException
{
}
