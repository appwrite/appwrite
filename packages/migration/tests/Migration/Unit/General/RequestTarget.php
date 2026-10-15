<?php

namespace Utopia\Tests\Unit\General;

use Override;
use Utopia\Migration\Target;

final class RequestTarget extends Target
{
    public function __construct(string $endpoint)
    {
        $this->endpoint = $endpoint;
    }

    #[Override]
    public static function getName(): string
    {
        return 'Request';
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
     * @param  array<string, mixed>  $params
     * @return array<mixed>|string
     */
    public function request(string $method, string $path, array $params = []): array|string
    {
        return $this->call($method, $path, [], $params);
    }
}
