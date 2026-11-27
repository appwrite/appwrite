<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Utopia\Database\Query;

trait ConnectionsBase
{
    public function testCreateGoogleConnection(): void
    {
        $serviceAccount = $this->seedGoogleServiceAccount();

        $created = $this->createGoogleConnection([
            'name' => 'Play Console',
            'serviceAccount' => $serviceAccount,
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('google', $created['body']['provider']);
        $this->assertSame('Play Console', $created['body']['name']);
        $this->assertSame('active', $created['body']['status']);
        $this->assertSame('', $created['body']['error']);
        $this->assertSame($serviceAccount['client_email'], $created['body']['account']['email']);
        $this->assertSame('appwrite-test', $created['body']['account']['projectId']);
        $this->assertNotEmpty($created['body']['checkedAt']);
        $this->assertArrayNotHasKey('credentials', $created['body']);

        $encoded = $this->createGoogleConnection([
            'serviceAccount' => \json_encode($this->seedGoogleServiceAccount()),
        ]);
        $this->assertSame(201, $encoded['headers']['status-code']);
        $this->assertSame('active', $encoded['body']['status']);

        /**
         * Test for FAILURE
         */
        $unseeded = $this->createGoogleConnection([
            'serviceAccount' => $this->unseededGoogleServiceAccount(),
        ]);
        $this->assertSame(400, $unseeded['headers']['status-code']);
        $this->assertSame('distribution_connection_invalid', $unseeded['body']['type']);

        $incomplete = $this->seedGoogleServiceAccount();
        unset($incomplete['private_key']);
        $missing = $this->createGoogleConnection(['serviceAccount' => $incomplete]);
        $this->assertSame(400, $missing['headers']['status-code']);
        $this->assertSame('distribution_connection_invalid', $missing['body']['type']);

        $duplicate = $this->createGoogleConnection(['connectionId' => $created['body']['$id']]);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('distribution_connection_already_exists', $duplicate['body']['type']);
    }

    public function testCreateAppleConnection(): void
    {
        $key = $this->seedAppleKey();

        $created = $this->createAppleConnection([
            'name' => 'App Store Connect',
            'keyId' => $key['keyId'],
            'issuerId' => $key['issuerId'],
            'privateKey' => $key['privateKey'],
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame('apple', $created['body']['provider']);
        $this->assertSame('App Store Connect', $created['body']['name']);
        $this->assertSame('active', $created['body']['status']);
        $this->assertSame($key['issuerId'], $created['body']['account']['issuerId']);
        $this->assertSame(\substr($key['keyId'], -4), $created['body']['account']['keyId']);
        $this->assertNotEmpty($created['body']['checkedAt']);
        $this->assertArrayNotHasKey('credentials', $created['body']);

        /**
         * Test for FAILURE
         */
        $unseeded = $this->createAppleConnection($this->unseededAppleKey());
        $this->assertSame(400, $unseeded['headers']['status-code']);
        $this->assertSame('distribution_connection_invalid', $unseeded['body']['type']);

        $notPem = $this->createAppleConnection(['privateKey' => 'not a private key']);
        $this->assertSame(400, $notPem['headers']['status-code']);
        $this->assertSame('distribution_connection_invalid', $notPem['body']['type']);

        $unsigned = $this->seedAppleKey(false);
        $agreements = $this->createAppleConnection($unsigned);
        $this->assertSame(400, $agreements['headers']['status-code']);
        $this->assertSame('distribution_connection_invalid', $agreements['body']['type']);

        $duplicate = $this->createAppleConnection(['connectionId' => $created['body']['$id']]);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('distribution_connection_already_exists', $duplicate['body']['type']);
    }

    public function testUpdateGoogleConnection(): void
    {
        $created = $this->createGoogleConnection();
        $this->assertSame(201, $created['headers']['status-code']);
        $connectionId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $renamed = $this->updateGoogleConnection($connectionId, ['name' => 'Renamed']);
        $this->assertSame(200, $renamed['headers']['status-code']);
        $this->assertSame('Renamed', $renamed['body']['name']);
        $this->assertSame($created['body']['account'], $renamed['body']['account']);
        $this->assertSame($created['body']['checkedAt'], $renamed['body']['checkedAt']);

        $serviceAccount = $this->seedGoogleServiceAccount();
        $replaced = $this->updateGoogleConnection($connectionId, ['serviceAccount' => $serviceAccount]);
        $this->assertSame(200, $replaced['headers']['status-code']);
        $this->assertSame('Renamed', $replaced['body']['name']);
        $this->assertSame($serviceAccount['client_email'], $replaced['body']['account']['email']);
        $this->assertSame('active', $replaced['body']['status']);
        $this->assertArrayNotHasKey('credentials', $replaced['body']);

        /**
         * Test for FAILURE
         */
        $invalid = $this->updateGoogleConnection($connectionId, ['serviceAccount' => $this->unseededGoogleServiceAccount()]);
        $this->assertSame(400, $invalid['headers']['status-code']);
        $this->assertSame('distribution_connection_invalid', $invalid['body']['type']);

        $unchanged = $this->getConnection($connectionId);
        $this->assertSame(200, $unchanged['headers']['status-code']);
        $this->assertSame($serviceAccount['client_email'], $unchanged['body']['account']['email']);

        $empty = $this->updateGoogleConnection($connectionId, []);
        $this->assertSame(400, $empty['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $empty['body']['type']);

        $wrongProvider = $this->updateAppleConnection($connectionId, ['name' => 'Apple']);
        $this->assertSame(404, $wrongProvider['headers']['status-code']);
        $this->assertSame('distribution_connection_not_found', $wrongProvider['body']['type']);

        $missing = $this->updateGoogleConnection('missing', ['name' => 'Missing']);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_connection_not_found', $missing['body']['type']);
    }

    public function testUpdateAppleConnection(): void
    {
        $created = $this->createAppleConnection();
        $this->assertSame(201, $created['headers']['status-code']);
        $connectionId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $renamed = $this->updateAppleConnection($connectionId, ['name' => 'Renamed']);
        $this->assertSame(200, $renamed['headers']['status-code']);
        $this->assertSame('Renamed', $renamed['body']['name']);
        $this->assertSame($created['body']['account'], $renamed['body']['account']);

        $key = $this->seedAppleKey();
        $replaced = $this->updateAppleConnection($connectionId, $key);
        $this->assertSame(200, $replaced['headers']['status-code']);
        $this->assertSame($key['issuerId'], $replaced['body']['account']['issuerId']);
        $this->assertSame(\substr($key['keyId'], -4), $replaced['body']['account']['keyId']);
        $this->assertArrayNotHasKey('credentials', $replaced['body']);

        /**
         * Test for FAILURE
         */
        $unseeded = $this->updateAppleConnection($connectionId, $this->unseededAppleKey());
        $this->assertSame(400, $unseeded['headers']['status-code']);
        $this->assertSame('distribution_connection_invalid', $unseeded['body']['type']);

        // A private key alone is checked together with the stored key ID, which it does not belong to.
        $otherKey = $this->seedAppleKey();
        $mismatched = $this->updateAppleConnection($connectionId, ['privateKey' => $otherKey['privateKey']]);
        $this->assertSame(400, $mismatched['headers']['status-code']);
        $this->assertSame('distribution_connection_invalid', $mismatched['body']['type']);

        $unchanged = $this->getConnection($connectionId);
        $this->assertSame(\substr($key['keyId'], -4), $unchanged['body']['account']['keyId']);

        $empty = $this->updateAppleConnection($connectionId, []);
        $this->assertSame(400, $empty['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $empty['body']['type']);

        $wrongProvider = $this->updateGoogleConnection($connectionId, ['name' => 'Google']);
        $this->assertSame(404, $wrongProvider['headers']['status-code']);
        $this->assertSame('distribution_connection_not_found', $wrongProvider['body']['type']);
    }

    public function testListConnections(): void
    {
        $token = \uniqid();
        $google = $this->createGoogleConnection(['name' => 'Listed ' . $token]);
        $apple = $this->createAppleConnection();
        $this->assertSame(201, $google['headers']['status-code']);
        $this->assertSame(201, $apple['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $all = $this->listConnections();
        $this->assertSame(200, $all['headers']['status-code']);
        $this->assertGreaterThanOrEqual(2, $all['body']['total']);
        $ids = \array_column($all['body']['connections'], '$id');
        $this->assertContains($google['body']['$id'], $ids);
        $this->assertContains($apple['body']['$id'], $ids);
        foreach ($all['body']['connections'] as $connection) {
            $this->assertArrayNotHasKey('credentials', $connection);
        }

        $filtered = $this->listConnections([Query::equal('provider', ['google'])->toString()]);
        $this->assertSame(200, $filtered['headers']['status-code']);
        $this->assertSame(['google'], \array_values(\array_unique(\array_column($filtered['body']['connections'], 'provider'))));
        $this->assertNotContains($apple['body']['$id'], \array_column($filtered['body']['connections'], '$id'));

        $searched = $this->listConnections([Query::search('search', $token)->toString()]);
        $this->assertSame(200, $searched['headers']['status-code']);
        $this->assertSame([$google['body']['$id']], \array_column($searched['body']['connections'], '$id'));

        $limited = $this->listConnections([Query::limit(1)->toString()], false);
        $this->assertSame(200, $limited['headers']['status-code']);
        $this->assertCount(1, $limited['body']['connections']);
        $this->assertSame(0, $limited['body']['total']);

        /**
         * Test for FAILURE
         */
        $unknown = $this->listConnections([Query::equal('credentials', ['x'])->toString()]);
        $this->assertSame(400, $unknown['headers']['status-code']);
    }

    public function testGetConnection(): void
    {
        $created = $this->createGoogleConnection();
        $this->assertSame(201, $created['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $connection = $this->getConnection($created['body']['$id']);
        $this->assertSame(200, $connection['headers']['status-code']);
        $this->assertSame($created['body']['$id'], $connection['body']['$id']);
        $this->assertSame($created['body']['name'], $connection['body']['name']);
        $this->assertSame($created['body']['account'], $connection['body']['account']);
        $this->assertArrayNotHasKey('credentials', $connection['body']);

        /**
         * Test for FAILURE
         */
        $missing = $this->getConnection('missing');
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_connection_not_found', $missing['body']['type']);
    }

    public function testDeleteConnection(): void
    {
        $created = $this->createAppleConnection();
        $this->assertSame(201, $created['headers']['status-code']);
        $connectionId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $deleted = $this->deleteConnection($connectionId);
        $this->assertSame(204, $deleted['headers']['status-code']);

        $this->assertSame(404, $this->getConnection($connectionId)['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $again = $this->deleteConnection($connectionId);
        $this->assertSame(404, $again['headers']['status-code']);
        $this->assertSame('distribution_connection_not_found', $again['body']['type']);
    }

    public function testDeleteConnectionInUse(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $connection = $this->createGoogleConnection();
        $this->assertSame(201, $connection['headers']['status-code']);
        $connectionId = $connection['body']['$id'];

        $target = $this->createTarget($app['body']['$id'], ['connectionId' => $connectionId]);
        $this->assertSame(201, $target['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $inUse = $this->deleteConnection($connectionId);
        $this->assertSame(409, $inUse['headers']['status-code']);
        $this->assertSame('distribution_connection_in_use', $inUse['body']['type']);
        $this->assertSame(200, $this->getConnection($connectionId)['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $detached = $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $app['body']['$id'] . '/targets/' . $target['body']['$id'], $this->headers(), [
            'connectionId' => '',
        ]);
        $this->assertSame(200, $detached['headers']['status-code']);
        $this->assertSame('', $detached['body']['connectionId']);

        $deleted = $this->deleteConnection($connectionId);
        $this->assertSame(204, $deleted['headers']['status-code']);

        $orphaned = $this->createGoogleConnection();
        $this->assertSame(201, $orphaned['headers']['status-code']);
        $owner = $this->createApp();
        $this->assertSame(201, $owner['headers']['status-code']);
        $referencing = $this->createTarget($owner['body']['$id'], ['connectionId' => $orphaned['body']['$id']]);
        $this->assertSame(201, $referencing['headers']['status-code']);

        $appDeleted = $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $owner['body']['$id'], $this->headers());
        $this->assertSame(204, $appDeleted['headers']['status-code']);

        $released = $this->deleteConnection($orphaned['body']['$id']);
        $this->assertSame(204, $released['headers']['status-code'], 'A deleted app kept its connection in use.');
    }

    public function testCreateTargetWithConnection(): void
    {
        $app = $this->createApp(['framework' => 'flutter']);
        $this->assertSame(201, $app['headers']['status-code']);
        $connection = $this->createGoogleConnection();
        $this->assertSame(201, $connection['headers']['status-code']);
        $connectionId = $connection['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $android = $this->createTarget($app['body']['$id'], ['connectionId' => $connectionId]);
        $this->assertSame(201, $android['headers']['status-code']);
        $this->assertSame($connectionId, $android['body']['connectionId']);

        /**
         * Test for FAILURE
         */
        $ios = $this->createTarget($app['body']['$id'], [
            'platform' => 'ios',
            'connectionId' => $connectionId,
        ]);
        $this->assertSame(400, $ios['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $ios['body']['type']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function updateGoogleConnection(string $connectionId, array $params): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/connections/google/' . $connectionId, $this->headers(), $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function updateAppleConnection(string $connectionId, array $params): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/connections/apple/' . $connectionId, $this->headers(), $params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getConnection(string $connectionId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/connections/' . $connectionId, $this->headers());
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function listConnections(array $queries = [], bool $total = true): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/connections', $this->headers(), [
            'queries' => $queries,
            'total' => $total,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteConnection(string $connectionId): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/distribution/connections/' . $connectionId, $this->headers());
    }

    /**
     * @return array<string, string>
     */
    private function unseededGoogleServiceAccount(): array
    {
        $key = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(\openssl_pkey_export($key, $privateKey));

        return [
            'type' => 'service_account',
            'project_id' => 'appwrite-test',
            'private_key' => $privateKey,
            'client_email' => 'unseeded' . \uniqid() . '@appwrite-test.iam.gserviceaccount.com',
        ];
    }

    /**
     * @return array{keyId: string, issuerId: string, privateKey: string}
     */
    private function unseededAppleKey(): array
    {
        $key = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(\openssl_pkey_export($key, $privateKey));

        return [
            'keyId' => \strtoupper(\substr(\bin2hex(\random_bytes(5)), 0, 10)),
            'issuerId' => ID::unique(),
            'privateKey' => $privateKey,
        ];
    }
}
