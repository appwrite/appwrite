<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class ScopesCustomServerTest extends Scope
{
    use DistributionBase;
    use ProjectCustom;
    use SideServer;

    /**
     * @var array<string, string>
     */
    private static array $keys = [];

    #[DataProvider('routes')]
    public function testRouteRequiresItsScope(string $method, string $path, string $scope): void
    {
        $others = \array_values(\array_diff(self::scopes(), [$scope]));

        /**
         * Test for FAILURE
         */
        $denied = $this->callWithScopes($method, $path, $others);

        $this->assertSame(401, $denied['headers']['status-code'], $method . ' ' . $path . ' answered without ' . $scope);
        $this->assertSame('general_unauthorized_scope', $denied['body']['type']);

        /**
         * Test for SUCCESS
         */
        $allowed = $this->callWithScopes($method, $path, [$scope]);

        $this->assertNotSame('general_unauthorized_scope', $allowed['body']['type'] ?? '', $method . ' ' . $path . ' refused ' . $scope);
        $this->assertLessThan(500, $allowed['headers']['status-code'], $method . ' ' . $path . ' failed with ' . $scope);
    }

    #[DataProvider('routes')]
    public function testSubmissionScopesGrantNoRoute(string $method, string $path, string $scope): void
    {
        $response = $this->callWithScopes($method, $path, [
            'distribution.submissions.read',
            'distribution.submissions.write',
        ]);

        $this->assertSame(401, $response['headers']['status-code'], $method . ' ' . $path . ' answered a submissions-only key');
        $this->assertSame('general_unauthorized_scope', $response['body']['type']);
    }

    /**
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    private function callWithScopes(string $method, string $path, array $scopes): array
    {
        return $this->client->call($method, $path, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->key($scopes),
        ]);
    }

    /**
     * @param list<string> $scopes
     */
    private function key(array $scopes): string
    {
        $cacheKey = $this->getProject()['$id'] . ':' . \implode(',', $scopes);

        return self::$keys[$cacheKey] ??= $this->getNewKey($scopes);
    }
}
