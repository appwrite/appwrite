<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Store\Apple;

use Psr\Http\Client\ClientInterface;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Store\Apple;

final class Frozen extends Apple
{
    public function __construct(
        Credential\Apple $credential,
        ClientInterface $client,
        private int $time,
        string $endpoint = self::ENDPOINT,
    ) {
        parent::__construct($credential, $client, $endpoint);
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }

    #[\Override]
    protected function now(): int
    {
        return $this->time;
    }
}
