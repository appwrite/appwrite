<?php

declare(strict_types=1);

namespace Utopia\Console\Tests;

/**
 * A consumer written before the rename, typed only against the old names.
 */
final readonly class OldNamesConsumer
{
    public function __construct(
        public \Utopia\Command $command,
    ) {
    }
}
