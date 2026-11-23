<?php

namespace Utopia\Abuse\Challenges\Nonce;

use Utopia\Abuse\Challenges\Nonce;

/**
 * Remembers nothing, so every non-empty nonce claims: turns the replay guard off.
 */
final readonly class None extends Nonce
{
    public function __construct(string $key = '', array $params = [])
    {
        parent::__construct($key, $params);
    }

    #[\Override]
    protected function claim(string $key, int $ttl): bool
    {
        return true;
    }
}
