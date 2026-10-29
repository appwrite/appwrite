<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Id;

final class AdminModeOriginTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    public function testAdminModeOrigin(): void
    {
        $projectId = $this->getProject()['$id'];
        $hostname = 'admin-origin-' . \uniqid() . '.example.com';
        $platformOrigin = 'http://' . $hostname;

        $platform = $this->client->call(Client::METHOD_POST, '/project/platforms/web', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'platformId' => Id::unique(),
            'name' => $hostname,
            'hostname' => $hostname,
        ]);
        $this->assertSame(201, $platform['headers']['status-code']);

        $consoleHeaders = [
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
        ];

        /**
         * Test for SUCCESS
         */
        $users = $this->client->call(Client::METHOD_GET, '/users', [
            ...$consoleHeaders,
            'origin' => 'http://localhost',
        ]);
        $this->assertSame(200, $users['headers']['status-code']);
        $this->assertSame('http://localhost', $users['headers']['access-control-allow-origin']);

        $jwt = $this->client->call(Client::METHOD_POST, '/account/jwts', [
            ...$consoleHeaders,
            'origin' => 'http://localhost',
        ]);
        $this->assertSame(201, $jwt['headers']['status-code']);

        $account = $this->client->call(Client::METHOD_GET, '/account', [
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $projectId . '=' . $this->getUser()['session'],
            'x-appwrite-project' => $projectId,
            'origin' => $platformOrigin,
        ]);
        $this->assertSame(200, $account['headers']['status-code']);
        $this->assertSame($platformOrigin, $account['headers']['access-control-allow-origin']);

        /**
         * Test for FAILURE
         */
        $users = $this->client->call(Client::METHOD_GET, '/users', [
            ...$consoleHeaders,
            'origin' => $platformOrigin,
        ]);
        $this->assertSame(403, $users['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_UNKNOWN_ORIGIN, $users['body']['type']);
        $this->assertArrayNotHasKey('access-control-allow-origin', $users['headers']);

        $jwt = $this->client->call(Client::METHOD_POST, '/account/jwts', [
            ...$consoleHeaders,
            'origin' => $platformOrigin,
        ]);
        $this->assertSame(403, $jwt['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_UNKNOWN_ORIGIN, $jwt['body']['type']);
        $this->assertArrayNotHasKey('access-control-allow-origin', $jwt['headers']);
    }
}
