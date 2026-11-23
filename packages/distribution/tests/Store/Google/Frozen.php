<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Store\Google;

use Psr\Http\Client\ClientInterface;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Store\Google;

final class Frozen extends Google
{
    public function __construct(
        Credential\Google $credential,
        ClientInterface $client,
        private int $time,
        string $endpoint = self::ENDPOINT,
        string $token = self::TOKEN,
    ) {
        parent::__construct($credential, $client, $endpoint, $token);
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
