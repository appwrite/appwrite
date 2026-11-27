<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Distribution\DistributionBase;

final class DistributionDisabledTest extends Scope
{
    use DistributionBase;
    use ProjectCustom;
    use SideServer;

    #[DataProvider('routes')]
    public function testRouteIsDisabledForKeys(string $method, string $path, string $scope): void
    {
        $this->skipWhenEnabled();

        $response = $this->client->call($method, $path, $this->headers());

        $this->assertSame(403, $response['headers']['status-code'], $method . ' ' . $path . ' answered a key while disabled');
        $this->assertSame('general_service_disabled', $response['body']['type']);
    }

    #[DataProvider('routes')]
    public function testRouteIsDisabledForConsole(string $method, string $path, string $scope): void
    {
        $this->skipWhenEnabled();

        $response = $this->client->call($method, $path, [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertSame(403, $response['headers']['status-code'], $method . ' ' . $path . ' answered the console while disabled');
        $this->assertSame('general_service_disabled', $response['body']['type']);
    }

    private function skipWhenEnabled(): void
    {
        if ($this->getConsoleVariables()['_APP_DISTRIBUTION_ENABLED'] ?? false) {
            $this->markTestSkipped('_APP_DISTRIBUTION is enabled on this stack; disabled-mode coverage needs a stack with _APP_DISTRIBUTION=disabled');
        }
    }
}
