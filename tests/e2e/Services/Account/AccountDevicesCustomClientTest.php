<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Account;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Id;

final class AccountDevicesCustomClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    private function createUserWithPassword(string $email, string $password): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'Switcher',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
    }

    public function testAccountSwitching(): void
    {
        $projectId = $this->getProject()['$id'];
        $deviceKey = 'a_device_' . $projectId;
        $sessionKey = 'a_session_' . $projectId;

        $emailOne = \uniqid() . 'switch1@localhost.test';
        $emailTwo = \uniqid() . 'switch2@localhost.test';
        $password = 'password';

        $this->createUserWithPassword($emailOne, $password);
        $this->createUserWithPassword($emailTwo, $password);

        /**
         * Test for SUCCESS - create a device
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/devices', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertArrayHasKey($deviceKey, $response['cookies']);
        $this->assertEquals('', $response['body']['activeSessionId']);
        $this->assertEquals([], $response['body']['sessions']);

        $deviceId = $response['body']['$id'];
        $deviceCookie = $response['cookies'][$deviceKey];

        /**
         * Test for SUCCESS - calling again returns the same device with 201 (DX)
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/devices', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($deviceId, $response['body']['$id']);

        /**
         * Test for SUCCESS - first session joins the device and becomes active
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $emailOne,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $sessionOneId = $response['body']['$id'];
        $userOneId = $response['body']['userId'];

        /**
         * Test for SUCCESS - a second session is allowed (no USER_SESSION_ALREADY_EXISTS) and becomes active
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $emailTwo,
            'password' => $password,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $sessionTwoId = $response['body']['$id'];
        $userTwoId = $response['body']['userId'];
        $this->assertNotEquals($userOneId, $userTwoId);

        /**
         * Test for SUCCESS - the device now holds both sessions, the last one active.
         * A created session joins the device from a post-response shutdown hook (like the
         * session-limit hook), so the active pointer is eventually consistent; poll for it.
         */
        $response = null;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $response = $this->client->call(Client::METHOD_GET, '/account/devices/current', [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'cookie' => $deviceKey . '=' . $deviceCookie,
                'x-appwrite-project' => $projectId,
            ]);
            if (
                $response['headers']['status-code'] === 200
                && \count($response['body']['sessions']) === 2
                && ($response['body']['activeSessionId'] ?? '') === $sessionTwoId
            ) {
                break;
            }
            \usleep(250000);
        }

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['sessions']);
        $this->assertEquals($sessionTwoId, $response['body']['activeSessionId']);

        /**
         * Test for SUCCESS - switch the active account to the first session
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/devices/current/session', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ], [
            'sessionId' => $sessionOneId,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($sessionOneId, $response['body']['$id']);
        $this->assertTrue($response['body']['current']);
        $this->assertArrayHasKey($sessionKey, $response['cookies']);

        $switchedSessionCookie = $response['cookies'][$sessionKey];

        /**
         * Test for SUCCESS - the rotated session cookie now authenticates as the first user
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $sessionKey . '=' . $switchedSessionCookie,
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($userOneId, $response['body']['$id']);

        /**
         * Test for SUCCESS - device reflects the first session as active
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/devices/current', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($sessionOneId, $response['body']['activeSessionId']);

        /**
         * Test for FAILURE - switching to a session the device does not hold
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/devices/current/session', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ], [
            'sessionId' => 'nonexistent',
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE - reading a device without a device cookie
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/devices/current', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for SUCCESS - deleting the active session leaves the device with none active
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/' . $sessionOneId, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $sessionKey . '=' . $switchedSessionCookie . '; ' . $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/account/devices/current', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('', $response['body']['activeSessionId']);
        $this->assertCount(1, $response['body']['sessions']);

        /**
         * Test for SUCCESS - delete the device, signing out of everything
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/account/devices/current', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        /**
         * Test for FAILURE - the device is gone
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/devices/current', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
    }
}
