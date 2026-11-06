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

    private function createUserWithPassword(string $email, string $password, string $name = 'Switcher'): string
    {
        $response = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'userId' => Id::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        return $response['body']['$id'];
    }

    /**
     * @return array{id: string, cookie: string}
     */
    private function createDevice(): array
    {
        $projectId = $this->getProject()['$id'];
        $deviceKey = 'a_device_' . $projectId;

        $response = $this->client->call(Client::METHOD_POST, '/account/devices', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertArrayHasKey($deviceKey, $response['cookies']);

        return ['id' => $response['body']['$id'], 'cookie' => $response['cookies'][$deviceKey]];
    }

    private function signIn(string $deviceCookie, string $email, string $password, array $extra = []): array
    {
        $projectId = $this->getProject()['$id'];

        return $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_device_' . $projectId . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ], \array_merge([
            'email' => $email,
            'password' => $password,
        ], $extra));
    }

    private function getDevice(string $deviceCookie): array
    {
        $projectId = $this->getProject()['$id'];

        return $this->client->call(Client::METHOD_GET, '/account/devices/current', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_device_' . $projectId . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ]);
    }

    private function setAccountDevicesPolicy(bool $enabled): void
    {
        $response = $this->client->call(Client::METHOD_PATCH, '/project/policies/account-devices', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'enabled' => $enabled,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testAccountSwitching(): void
    {
        $projectId = $this->getProject()['$id'];
        $deviceKey = 'a_device_' . $projectId;
        $sessionKey = 'a_session_' . $projectId;

        $emailOne = \uniqid() . 'switch1@localhost.test';
        $emailTwo = \uniqid() . 'switch2@localhost.test';
        $password = 'password';

        $this->createUserWithPassword($emailOne, $password, 'Switcher One');
        $this->createUserWithPassword($emailTwo, $password, 'Switcher Two');

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
        $this->assertNotEmpty($response['body']['secret']);

        $deviceId = $response['body']['$id'];
        $deviceCookie = $response['cookies'][$deviceKey];
        $deviceExpire = $response['body']['expire'];

        /**
         * Test for SUCCESS - calling again returns the same device with 201 (DX), without the secret
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/devices', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($deviceId, $response['body']['$id']);
        $this->assertEmpty($response['body']['secret'] ?? '');

        /**
         * Test for SUCCESS - first session joins the device and becomes active, synchronously:
         * the very next read already shows it.
         */
        $response = $this->signIn($deviceCookie, $emailOne, $password);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals('Switcher One', $response['body']['userName']);
        $sessionOneId = $response['body']['$id'];
        $userOneId = $response['body']['userId'];

        $response = $this->getDevice($deviceCookie);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['sessions']);
        $this->assertEquals($sessionOneId, $response['body']['activeSessionId']);
        $this->assertTrue($response['body']['sessions'][0]['current']);
        $this->assertEquals('Switcher One', $response['body']['sessions'][0]['userName']);

        /**
         * Test for SUCCESS - a second session is allowed (no USER_SESSION_ALREADY_EXISTS) and becomes active
         */
        $response = $this->signIn($deviceCookie, $emailTwo, $password);

        $this->assertEquals(201, $response['headers']['status-code']);
        $sessionTwoId = $response['body']['$id'];
        $userTwoId = $response['body']['userId'];
        $this->assertNotEquals($userOneId, $userTwoId);

        $response = $this->getDevice($deviceCookie);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['sessions']);
        $this->assertEquals($sessionTwoId, $response['body']['activeSessionId']);

        $names = \array_column($response['body']['sessions'], 'userName', '$id');
        $this->assertEquals('Switcher One', $names[$sessionOneId]);
        $this->assertEquals('Switcher Two', $names[$sessionTwoId]);

        /**
         * Test for SUCCESS - the device lives as long as its newest session: the expiry moved out
         */
        $this->assertGreaterThanOrEqual($deviceExpire, $response['body']['expire']);
        $deviceExpire = $response['body']['expire'];

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
        $this->assertEquals('Switcher One', $response['body']['userName']);
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
        $response = $this->getDevice($deviceCookie);

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
        $this->assertEquals('user_device_session_not_found', $response['body']['type']);

        /**
         * Test for FAILURE - reading a device without a device cookie
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/devices/current', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('user_device_not_found', $response['body']['type']);

        /**
         * Test for SUCCESS - deleting the active session leaves the device with none active (by design)
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/' . $sessionOneId, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $sessionKey . '=' . $switchedSessionCookie . '; ' . $deviceKey . '=' . $deviceCookie,
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        $response = $this->getDevice($deviceCookie);

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
        $response = $this->getDevice($deviceCookie);

        $this->assertEquals(404, $response['headers']['status-code']);
        $this->assertEquals('user_device_not_found', $response['body']['type']);
    }

    public function testDeviceHoldsOneSessionPerUser(): void
    {
        $projectId = $this->getProject()['$id'];
        $sessionKey = 'a_session_' . $projectId;

        $email = \uniqid() . 'twice@localhost.test';
        $password = 'password';
        $this->createUserWithPassword($email, $password);

        $device = $this->createDevice();

        $first = $this->signIn($device['cookie'], $email, $password);
        $this->assertEquals(201, $first['headers']['status-code']);
        $firstSessionId = $first['body']['$id'];
        $firstSessionCookie = $first['cookies'][$sessionKey];

        /**
         * Test for SUCCESS - signing in again as the same user replaces the session instead of adding one
         */
        $second = $this->signIn($device['cookie'], $email, $password);
        $this->assertEquals(201, $second['headers']['status-code']);
        $secondSessionId = $second['body']['$id'];
        $this->assertNotEquals($firstSessionId, $secondSessionId);

        $response = $this->getDevice($device['cookie']);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['sessions']);
        $this->assertEquals($secondSessionId, $response['body']['sessions'][0]['$id']);
        $this->assertEquals($secondSessionId, $response['body']['activeSessionId']);

        /**
         * Test for FAILURE - the replaced session is signed out
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $sessionKey . '=' . $firstSessionCookie,
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * Test for SUCCESS - the new session works
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => $sessionKey . '=' . $second['cookies'][$sessionKey],
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
    }

    public function testDeviceLivesAsLongAsItsNewestSession(): void
    {
        $projectId = $this->getProject()['$id'];
        $deviceKey = 'a_device_' . $projectId;

        $email = \uniqid() . 'expire@localhost.test';
        $password = 'password';
        $this->createUserWithPassword($email, $password);

        $device = $this->createDevice();
        $created = $this->getDevice($device['cookie']);
        $this->assertEquals(200, $created['headers']['status-code']);
        $deviceExpire = $created['body']['expire'];

        /**
         * Test for SUCCESS - a short session does not shorten the device
         */
        $short = $this->signIn($device['cookie'], $email, $password, ['duration' => 60]);
        $this->assertEquals(201, $short['headers']['status-code']);
        $this->assertLessThan($deviceExpire, $short['body']['expire']);

        $response = $this->getDevice($device['cookie']);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($deviceExpire, $response['body']['expire']);

        /**
         * Test for SUCCESS - a session that outlives the device extends it, and the cookie is re-issued to match
         */
        $long = $this->signIn($device['cookie'], $email, $password);
        $this->assertEquals(201, $long['headers']['status-code']);
        $this->assertGreaterThan($deviceExpire, $long['body']['expire']);
        $this->assertArrayHasKey($deviceKey, $long['cookies']);
        $this->assertEquals($device['cookie'], $long['cookies'][$deviceKey]);

        $response = $this->getDevice($device['cookie']);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($long['body']['expire'], $response['body']['expire']);
        $this->assertGreaterThan($deviceExpire, $response['body']['expire']);
    }

    public function testAccountDevicesPolicy(): void
    {
        $projectId = $this->getProject()['$id'];

        /**
         * Test for FAILURE - devices cannot be created while the policy is off
         */
        $this->setAccountDevicesPolicy(false);

        $response = $this->client->call(Client::METHOD_GET, '/project/policies/account-devices', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertFalse($response['body']['enabled']);

        $response = $this->client->call(Client::METHOD_POST, '/account/devices', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(501, $response['headers']['status-code']);
        $this->assertEquals('user_auth_method_unsupported', $response['body']['type']);

        /**
         * Test for SUCCESS - and can again once it is back on
         */
        $this->setAccountDevicesPolicy(true);

        $response = $this->client->call(Client::METHOD_POST, '/account/devices', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
    }
}
