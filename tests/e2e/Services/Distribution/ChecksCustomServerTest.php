<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class ChecksCustomServerTest extends Scope
{
    use ChecksBase;
    use DistributionBase;
    use MockStores;
    use ProjectCustom;
    use SideServer;

    private const int STORE_APP_MISSING = 13;
    private const int SIGNING_MISSING = 14;
    private const int READY = 16;
    private const int CONNECTION_UNAUTHORIZED = 17;
    private const int SIGNING_EXPIRING = 18;

    public function testCreateCheckStoreAppMissing(): void
    {
        $signingIdentity = $this->createCheckSigningIdentity();
        $this->assertSame(201, $signingIdentity['headers']['status-code']);

        $prepared = $this->prepareGoogleTarget(self::STORE_APP_MISSING, [
            'signingIdentityId' => $signingIdentity['body']['$id'],
        ]);

        $check = $this->createCheck($prepared['appId'], ['targetId' => $prepared['targetId']]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(200, $check['headers']['status-code']);
        $this->assertFalse($check['body']['ready']);
        $this->assertSame(['store_app_missing'], \array_column($check['body']['issues'], 'code'));

        $issue = $check['body']['issues'][0];
        $this->assertSame('error', $issue['severity']);
        $this->assertSame('android', $issue['platform']);
        $this->assertNotEmpty($issue['message']);
        $this->assertNotEmpty($issue['remediation']);
    }

    public function testCreateCheckSigningMissing(): void
    {
        $prepared = $this->prepareGoogleTarget(self::SIGNING_MISSING);
        $this->seedGoogleApplication($prepared['identifier'], $prepared['email']);

        $check = $this->createCheck($prepared['appId'], ['targetId' => $prepared['targetId']]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(200, $check['headers']['status-code']);
        $this->assertFalse($check['body']['ready']);
        $this->assertSame(['signing_missing'], \array_column($check['body']['issues'], 'code'));

        $issue = $check['body']['issues'][0];
        $this->assertSame('error', $issue['severity']);
        $this->assertSame('android', $issue['platform']);
        $this->assertSame('signingIdentityId', $issue['field']);
        $this->assertNotEmpty($issue['message']);
        $this->assertNotEmpty($issue['remediation']);
        $this->assertFalse($issue['manualActionRequired']);
    }

    public function testCreateCheckReady(): void
    {
        $signingIdentity = $this->createCheckSigningIdentity();
        $this->assertSame(201, $signingIdentity['headers']['status-code']);

        $prepared = $this->prepareGoogleTarget(self::READY, [
            'signingIdentityId' => $signingIdentity['body']['$id'],
        ]);
        $this->seedGoogleApplication($prepared['identifier'], $prepared['email']);

        /**
         * Test for SUCCESS
         */
        foreach (['default' => [], 'internal' => ['track' => 'internal']] as $track => $params) {
            $check = $this->createCheck($prepared['appId'], ['targetId' => $prepared['targetId'], ...$params]);
            $this->assertSame(200, $check['headers']['status-code'], $track);
            $this->assertSame([], \array_column($check['body']['issues'], 'code'), $track);
            $this->assertTrue($check['body']['ready'], $track);
        }

        $uploads = $this->googleUploads($prepared['identifier']);
        $this->assertSame(0, $uploads['sessions'], 'a check opened an upload session');
    }

    public function testCreateCheckConnectionUnauthorized(): void
    {
        $signingIdentity = $this->createCheckSigningIdentity();
        $this->assertSame(201, $signingIdentity['headers']['status-code']);

        $prepared = $this->prepareGoogleTarget(self::CONNECTION_UNAUTHORIZED, [
            'signingIdentityId' => $signingIdentity['body']['$id'],
        ]);
        $this->seedGoogleApplication($prepared['identifier'], $prepared['email']);

        $key = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $rotated = $this->client->call(Client::METHOD_POST, '/mock/distribution/google/seeds/service-accounts', $this->mockHeaders(), [
            'email' => $prepared['email'],
            'publicKey' => \openssl_pkey_get_details($key)['key'],
        ]);
        $this->assertSame(201, $rotated['headers']['status-code']);

        $check = $this->createCheck($prepared['appId'], ['targetId' => $prepared['targetId']]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(200, $check['headers']['status-code']);
        $this->assertFalse($check['body']['ready']);
        $this->assertSame(['connection_unauthorized'], \array_column($check['body']['issues'], 'code'));

        $issue = $check['body']['issues'][0];
        $this->assertSame('error', $issue['severity']);
        $this->assertSame('android', $issue['platform']);
        $this->assertSame('connectionId', $issue['field']);
        $this->assertNotEmpty($issue['message']);
        $this->assertNotEmpty($issue['remediation']);
        $this->assertFalse($issue['manualActionRequired']);
    }

    public function testCreateCheckSigningExpiring(): void
    {
        $signingIdentity = $this->createCheckSigningIdentity(days: 3);
        $this->assertSame(201, $signingIdentity['headers']['status-code']);

        $prepared = $this->prepareGoogleTarget(self::SIGNING_EXPIRING, [
            'signingIdentityId' => $signingIdentity['body']['$id'],
        ]);
        $this->seedGoogleApplication($prepared['identifier'], $prepared['email']);

        $check = $this->createCheck($prepared['appId'], ['targetId' => $prepared['targetId']]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(200, $check['headers']['status-code']);
        $this->assertTrue($check['body']['ready'], 'A warning does not block.');
        $this->assertSame(['signing_expired'], \array_column($check['body']['issues'], 'code'));

        $issue = $check['body']['issues'][0];
        $this->assertSame('warning', $issue['severity']);
        $this->assertSame('android', $issue['platform']);
        $this->assertSame('signingIdentityId', $issue['field']);
        $this->assertNotEmpty($issue['message']);
        $this->assertNotEmpty($issue['remediation']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{appId: string, targetId: string, identifier: string, email: string}
     */
    private function prepareGoogleTarget(int $variant, array $params = []): array
    {
        $identifier = 'io.appwrite.distribution.e2e' . $variant;

        $serviceAccount = $this->seedGoogleServiceAccount();
        $connection = $this->createGoogleConnection(['serviceAccount' => $serviceAccount]);
        $this->assertSame(201, $connection['headers']['status-code']);

        $app = $this->createApp(['framework' => 'android']);
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        $target = $this->createTarget($appId, \array_merge([
            'platform' => 'android',
            'identifier' => $identifier,
            'connectionId' => $connection['body']['$id'],
            'track' => 'internal',
        ], $params));
        $this->assertSame(201, $target['headers']['status-code']);

        return [
            'appId' => $appId,
            'targetId' => $target['body']['$id'],
            'identifier' => $identifier,
            'email' => $serviceAccount['client_email'],
        ];
    }
}
