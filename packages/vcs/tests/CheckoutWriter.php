<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\GitHub;

/**
 * A Git adapter that exposes the shared checkout write, so its confinement
 * can be exercised against a directory on disk.
 */
final class CheckoutWriter extends GitHub
{
    public function __construct()
    {
        parent::__construct(new Cache(new None()));
    }

    public function write(string $directory, string $filepath, string $content): void
    {
        $this->writeCheckoutFile($directory, $this->confinedPath($filepath), $content);
    }
}
