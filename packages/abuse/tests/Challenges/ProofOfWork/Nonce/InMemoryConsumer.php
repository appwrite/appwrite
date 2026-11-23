<?php

namespace Utopia\Abuse\Tests\Challenges\ProofOfWork\Nonce;

use Utopia\Abuse\Challenges\ProofOfWork\Nonce\Consumer;
use Utopia\Abuse\Challenges\ProofOfWork\Nonce\Outcome;

/**
 * In-memory {@see Consumer} for tests: first consume of a nonce is Fresh,
 * any later consume is Consumed (replay). `$unavailable` simulates a
 * backing-store outage where every consume reports Unavailable.
 */
final class InMemoryConsumer implements Consumer
{
    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(private readonly bool $unavailable = false)
    {
    }

    public function consume(string $projectId, string $nonce, int $ttl): Outcome
    {
        if ($this->unavailable) {
            return Outcome::Unavailable;
        }

        $key = $projectId . ':' . $nonce;
        if ($nonce === '' || isset($this->seen[$key])) {
            return Outcome::Consumed;
        }

        $this->seen[$key] = true;

        return Outcome::Fresh;
    }
}
