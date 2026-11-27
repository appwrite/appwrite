<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use Utopia\VCS\Adapter\Git\Bitbucket;

/**
 * Bitbucket with its HTTP call opened up, so a test can drive it against a local endpoint.
 */
final class ProviderBitbucket extends Bitbucket
{
    /**
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    public function request(string $method, string $path, array $params): array
    {
        return $this->call($method, $path, [], $params);
    }
}
