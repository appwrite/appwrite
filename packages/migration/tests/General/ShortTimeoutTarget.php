<?php

namespace Utopia\Migration\Tests\General;

use Override;
use Utopia\Migration\Target;

final class ShortTimeoutTarget extends Target
{
    public const int CONNECT_TIMEOUT = 1;

    public const int STALL_TIMEOUT = 3;

    public const int REQUEST_TIMEOUT = 1;

    #[Override]
    public static function getName(): string
    {
        return 'ShortTimeout';
    }

    #[Override]
    public static function getSupportedResources(): array
    {
        return [];
    }

    #[Override]
    public function run(array $resources, callable $callback, string $rootResourceId = ''): void
    {
    }

    #[Override]
    public function report(array $resources = [], array $resourceIds = []): array
    {
        return [];
    }

    /**
     * @return array<mixed>|string
     *
     * @throws \Exception
     */
    public function fetch(string $url): array|string
    {
        return $this->call('GET', $url);
    }
}
