<?php

declare(strict_types=1);

namespace Utopia\Cache\Exception;

/**
 * A cache write that did not reach the cache, so whatever it was meant to store or remove is unchanged.
 */
class Unavailable extends \RuntimeException
{
}
