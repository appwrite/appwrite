<?php

declare(strict_types=1);

namespace Utopia\Client\Tests;

/**
 * A consumer written before the rename, typed only against the old names.
 */
final readonly class OldNamesConsumer
{
    public \Utopia\Psr18\StreamingClientInterface $stream;

    public function __construct(
        public \Utopia\Client $client,
    ) {
        $this->stream = $client;
    }
}
