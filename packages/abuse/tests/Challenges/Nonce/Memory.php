<?php

namespace Utopia\Abuse\Tests\Challenges\Nonce;

use Utopia\Abuse\Challenges\Nonce;

/**
 * In-memory nonce store for tests. Claimed keys are kept in a shared
 * ArrayObject so clones made by withParams() see the same state; `$error`
 * makes every claim throw, like an unreachable store.
 */
final readonly class Memory extends Nonce
{
    /**
     * @param  \ArrayObject<string, int>  $claimed  key => ttl
     */
    public function __construct(
        string $key = 'pow',
        public \ArrayObject $claimed = new \ArrayObject(),
        private ?\Throwable $error = null,
    ) {
        parent::__construct($key);
    }

    #[\Override]
    protected function claim(string $key, int $ttl): bool
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        if ($this->claimed->offsetExists($key)) {
            return false;
        }

        $this->claimed[$key] = $ttl;

        return true;
    }
}
