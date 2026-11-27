<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Store\Google;

use Psr\Http\Client\ClientInterface;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Store\Google;

final class Frozen extends Google
{
    /**
     * @param int<1, max>|null $chunk
     */
    public function __construct(
        Credential\Google $credential,
        ClientInterface $client,
        private int $time,
        string $endpoint = self::ENDPOINT,
        string $token = self::TOKEN,
        private readonly ?int $chunk = null,
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

    /**
     * @return int<1, max>
     */
    #[\Override]
    protected function chunk(): int
    {
        return $this->chunk ?? parent::chunk();
    }
}
