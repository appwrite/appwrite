<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Query;

trait TargetsBase
{
    public function testCreateTarget(): void
    {
        $app = $this->createApp(['framework' => 'flutter']);
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $identifier = 'io.appwrite.t' . \uniqid();

        /**
         * Test for SUCCESS
         */
        $android = $this->createTarget($appId, ['platform' => 'android', 'identifier' => $identifier]);
        $this->assertSame(201, $android['headers']['status-code']);
        $this->assertNotEmpty($android['body']['$id']);
        $this->assertSame($appId, $android['body']['appId']);
        $this->assertSame('android', $android['body']['platform']);
        $this->assertSame($identifier, $android['body']['identifier']);
        $this->assertSame('', $android['body']['connectionId']);
        $this->assertSame('', $android['body']['signingIdentityId']);
        $this->assertSame('', $android['body']['storeAppId']);
        $this->assertSame('', $android['body']['track']);
        $this->assertFalse($android['body']['automatic']);
        $this->assertSame('automatic', $android['body']['versionStrategy']);
        $this->assertSame(0, $android['body']['versionCode']);
        $this->assertSame('', $android['body']['installCommand']);
        $this->assertSame('', $android['body']['buildCommand']);
        $this->assertSame('', $android['body']['outputPath']);
        $this->assertSame([], $android['body']['configuration']);
        $this->assertSame('', $android['body']['specification']);
        $this->assertSame(0, $android['body']['timeout']);

        $ios = $this->createTarget($appId, [
            'platform' => 'ios',
            'identifier' => 'io.appwrite.t' . \uniqid(),
            'track' => 'testflight',
            'automatic' => true,
            'versionStrategy' => 'manual',
            'buildCommand' => 'flutter build ipa --release',
            'configuration' => ['scheme' => 'Runner', 'xcode' => '16.2'],
            'specification' => 'd-4vcpu-8gb',
            'timeout' => 900,
        ]);
        $this->assertSame(201, $ios['headers']['status-code']);
        $this->assertSame('ios', $ios['body']['platform']);
        $this->assertSame('testflight', $ios['body']['track']);
        $this->assertTrue($ios['body']['automatic']);
        $this->assertSame('manual', $ios['body']['versionStrategy']);
        $this->assertSame('flutter build ipa --release', $ios['body']['buildCommand']);
        $this->assertSame(['scheme' => 'Runner', 'xcode' => '16.2'], $ios['body']['configuration']);
        $this->assertSame('d-4vcpu-8gb', $ios['body']['specification']);
        $this->assertSame(900, $ios['body']['timeout']);

        $fetched = $this->getTarget($appId, $ios['body']['$id']);
        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame(['scheme' => 'Runner', 'xcode' => '16.2'], $fetched['body']['configuration']);

        $other = $this->createApp(['framework' => 'android']);
        $this->assertSame(201, $other['headers']['status-code']);
        $otherId = $other['body']['$id'];

        $custom = $this->createTarget($otherId, ['track' => 'closed-testing']);
        $this->assertSame(201, $custom['headers']['status-code']);
        $this->assertSame('closed-testing', $custom['body']['track']);

        /**
         * Test for FAILURE
         */
        $duplicate = $this->createTarget($appId, ['platform' => 'android']);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('distribution_target_already_exists', $duplicate['body']['type']);

        $fresh = $this->createApp(['framework' => 'flutter']);
        $this->assertSame(201, $fresh['headers']['status-code']);
        $freshId = $fresh['body']['$id'];

        $duplicateId = $this->createTarget($freshId, ['targetId' => $android['body']['$id']]);
        $this->assertSame(409, $duplicateId['headers']['status-code']);
        $this->assertSame('distribution_target_already_exists', $duplicateId['body']['type']);

        $unsupported = $this->createTarget($otherId, ['platform' => 'ios']);
        $this->assertSame(400, $unsupported['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $unsupported['body']['type']);

        $platform = $this->createTarget($freshId, ['platform' => 'windows']);
        $this->assertSame(400, $platform['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $platform['body']['type']);

        $identifierMissing = $this->createTarget($freshId, ['identifier' => '']);
        $this->assertSame(400, $identifierMissing['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $identifierMissing['body']['type']);

        $connection = $this->createTarget($freshId, ['connectionId' => ID::unique()]);
        $this->assertSame(404, $connection['headers']['status-code']);
        $this->assertSame('distribution_connection_not_found', $connection['body']['type']);

        $signingIdentity = $this->createTarget($freshId, ['signingIdentityId' => ID::unique()]);
        $this->assertSame(404, $signingIdentity['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_not_found', $signingIdentity['body']['type']);

        $track = $this->createTarget($freshId, ['platform' => 'ios', 'track' => 'beta']);
        $this->assertSame(400, $track['headers']['status-code']);
        $this->assertSame('distribution_track_invalid', $track['body']['type']);

        $configurationKey = $this->createTarget($freshId, ['configuration' => ['foo' => 'bar']]);
        $this->assertSame(400, $configurationKey['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $configurationKey['body']['type']);

        $configurationValue = $this->createTarget($freshId, ['configuration' => ['flavor' => ['production']]]);
        $this->assertSame(400, $configurationValue['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $configurationValue['body']['type']);

        $specification = $this->createTarget($freshId, ['specification' => 'nope']);
        $this->assertSame(400, $specification['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $specification['body']['type']);

        $timeout = $this->createTarget($freshId, ['timeout' => -1]);
        $this->assertSame(400, $timeout['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $timeout['body']['type']);

        $versionStrategy = $this->createTarget($freshId, ['versionStrategy' => 'random']);
        $this->assertSame(400, $versionStrategy['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $versionStrategy['body']['type']);

        $this->assertSame(0, $this->listTargets($freshId)['body']['total']);

        $missing = $this->createTarget(ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testListTargets(): void
    {
        $app = $this->createApp(['framework' => 'flutter']);
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        $android = $this->createTarget($appId, ['platform' => 'android']);
        $ios = $this->createTarget($appId, ['platform' => 'ios']);
        $this->assertSame(201, $android['headers']['status-code']);
        $this->assertSame(201, $ios['headers']['status-code']);

        $other = $this->createApp(['framework' => 'flutter']);
        $this->assertSame(201, $other['headers']['status-code']);
        $foreign = $this->createTarget($other['body']['$id'], ['platform' => 'android']);
        $this->assertSame(201, $foreign['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $targets = $this->listTargets($appId);
        $this->assertSame(200, $targets['headers']['status-code']);
        $this->assertSame(2, $targets['body']['total']);
        $ids = \array_column($targets['body']['targets'], '$id');
        $this->assertCount(2, $ids);
        $this->assertContains($android['body']['$id'], $ids);
        $this->assertContains($ios['body']['$id'], $ids);
        $this->assertNotContains($foreign['body']['$id'], $ids);

        $filtered = $this->listTargets($appId, [Query::equal('platform', ['ios'])->toString()]);
        $this->assertSame(200, $filtered['headers']['status-code']);
        $this->assertSame(1, $filtered['body']['total']);
        $this->assertSame([$ios['body']['$id']], \array_column($filtered['body']['targets'], '$id'));

        $paged = $this->listTargets($appId, [
            Query::orderAsc('$sequence')->toString(),
            Query::cursorAfter(new Document(['$id' => $android['body']['$id']]))->toString(),
        ]);
        $this->assertSame(200, $paged['headers']['status-code']);
        $this->assertSame([$ios['body']['$id']], \array_column($paged['body']['targets'], '$id'));

        $untotaled = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/targets', $this->headers(), [
            'total' => false,
        ]);
        $this->assertSame(200, $untotaled['headers']['status-code']);
        $this->assertSame(0, $untotaled['body']['total']);
        $this->assertCount(2, $untotaled['body']['targets']);

        /**
         * Test for FAILURE
         */
        $unknown = $this->listTargets($appId, [Query::equal('appInternalId', ['1'])->toString()]);
        $this->assertSame(400, $unknown['headers']['status-code']);

        $cursor = $this->listTargets($appId, [Query::cursorAfter(new Document(['$id' => $foreign['body']['$id']]))->toString()]);
        $this->assertSame(400, $cursor['headers']['status-code']);
        $this->assertSame('general_cursor_not_found', $cursor['body']['type']);

        $missing = $this->listTargets(ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testGetTarget(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->createTarget($appId);
        $this->assertSame(201, $created['headers']['status-code']);
        $targetId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $target = $this->getTarget($appId, $targetId);
        $this->assertSame(200, $target['headers']['status-code']);
        $this->assertSame($targetId, $target['body']['$id']);
        $this->assertSame($appId, $target['body']['appId']);
        $this->assertSame($created['body']['identifier'], $target['body']['identifier']);

        /**
         * Test for FAILURE
         */
        $other = $this->createApp();
        $this->assertSame(201, $other['headers']['status-code']);

        $foreign = $this->getTarget($other['body']['$id'], $targetId);
        $this->assertSame(404, $foreign['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $foreign['body']['type']);

        $missing = $this->getTarget($appId, ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $missing['body']['type']);

        $app = $this->getTarget(ID::unique(), $targetId);
        $this->assertSame(404, $app['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $app['body']['type']);
    }

    public function testUpdateTarget(): void
    {
        $app = $this->createApp(['framework' => 'flutter']);
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->createTarget($appId, ['platform' => 'ios', 'track' => 'testflight']);
        $this->assertSame(201, $created['headers']['status-code']);
        $targetId = $created['body']['$id'];
        $identifier = 'io.appwrite.u' . \uniqid();

        /**
         * Test for SUCCESS
         */
        $updated = $this->updateTarget($appId, $targetId, [
            'identifier' => $identifier,
            'track' => 'production',
            'automatic' => true,
            'versionStrategy' => 'manual',
            'installCommand' => 'flutter pub get',
            'buildCommand' => 'flutter build ipa --release',
            'outputPath' => 'build/ios/ipa/*.ipa',
            'configuration' => ['scheme' => 'Runner', 'configuration' => 'Release'],
            'specification' => 'd-8vcpu-16gb',
            'timeout' => 1200,
        ]);
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($identifier, $updated['body']['identifier']);
        $this->assertSame('ios', $updated['body']['platform']);
        $this->assertSame('production', $updated['body']['track']);
        $this->assertTrue($updated['body']['automatic']);
        $this->assertSame('manual', $updated['body']['versionStrategy']);
        $this->assertSame('flutter pub get', $updated['body']['installCommand']);
        $this->assertSame('flutter build ipa --release', $updated['body']['buildCommand']);
        $this->assertSame('build/ios/ipa/*.ipa', $updated['body']['outputPath']);
        $this->assertSame(['scheme' => 'Runner', 'configuration' => 'Release'], $updated['body']['configuration']);
        $this->assertSame('d-8vcpu-16gb', $updated['body']['specification']);
        $this->assertSame(1200, $updated['body']['timeout']);

        $partial = $this->updateTarget($appId, $targetId, ['automatic' => false]);
        $this->assertSame(200, $partial['headers']['status-code']);
        $this->assertFalse($partial['body']['automatic']);
        $this->assertSame($identifier, $partial['body']['identifier']);
        $this->assertSame('production', $partial['body']['track']);
        $this->assertSame(['scheme' => 'Runner', 'configuration' => 'Release'], $partial['body']['configuration']);

        $cleared = $this->updateTarget($appId, $targetId, [
            'connectionId' => '',
            'signingIdentityId' => '',
            'track' => '',
            'buildCommand' => '',
            'configuration' => [],
            'specification' => '',
        ]);
        $this->assertSame(200, $cleared['headers']['status-code']);
        $this->assertSame('', $cleared['body']['connectionId']);
        $this->assertSame('', $cleared['body']['signingIdentityId']);
        $this->assertSame('', $cleared['body']['track']);
        $this->assertSame('', $cleared['body']['buildCommand']);
        $this->assertSame([], $cleared['body']['configuration']);
        $this->assertSame('', $cleared['body']['specification']);
        $this->assertSame('flutter pub get', $cleared['body']['installCommand']);

        $fetched = $this->getTarget($appId, $targetId);
        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame($identifier, $fetched['body']['identifier']);
        $this->assertSame('', $fetched['body']['track']);

        /**
         * Test for FAILURE
         */
        $empty = $this->updateTarget($appId, $targetId, []);
        $this->assertSame(400, $empty['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $empty['body']['type']);

        $track = $this->updateTarget($appId, $targetId, ['track' => 'beta']);
        $this->assertSame(400, $track['headers']['status-code']);
        $this->assertSame('distribution_track_invalid', $track['body']['type']);

        $connection = $this->updateTarget($appId, $targetId, ['connectionId' => ID::unique()]);
        $this->assertSame(404, $connection['headers']['status-code']);
        $this->assertSame('distribution_connection_not_found', $connection['body']['type']);

        $signingIdentity = $this->updateTarget($appId, $targetId, ['signingIdentityId' => ID::unique()]);
        $this->assertSame(404, $signingIdentity['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_not_found', $signingIdentity['body']['type']);

        $configuration = $this->updateTarget($appId, $targetId, ['configuration' => ['foo' => 'bar']]);
        $this->assertSame(400, $configuration['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $configuration['body']['type']);

        $specification = $this->updateTarget($appId, $targetId, ['specification' => 'nope']);
        $this->assertSame(400, $specification['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $specification['body']['type']);

        $unchanged = $this->getTarget($appId, $targetId);
        $this->assertSame('', $unchanged['body']['track']);
        $this->assertSame([], $unchanged['body']['configuration']);

        $other = $this->createApp();
        $this->assertSame(201, $other['headers']['status-code']);

        $foreign = $this->updateTarget($other['body']['$id'], $targetId, ['automatic' => true]);
        $this->assertSame(404, $foreign['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $foreign['body']['type']);

        $missing = $this->updateTarget($appId, ID::unique(), ['automatic' => true]);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $missing['body']['type']);

        $app = $this->updateTarget(ID::unique(), $targetId, ['automatic' => true]);
        $this->assertSame(404, $app['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $app['body']['type']);
    }

    public function testDeleteTarget(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->createTarget($appId, ['platform' => 'android']);
        $this->assertSame(201, $created['headers']['status-code']);
        $targetId = $created['body']['$id'];

        $other = $this->createApp();
        $this->assertSame(201, $other['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $foreign = $this->deleteTarget($other['body']['$id'], $targetId);
        $this->assertSame(404, $foreign['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $foreign['body']['type']);
        $this->assertSame(200, $this->getTarget($appId, $targetId)['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $deleted = $this->deleteTarget($appId, $targetId);
        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertEmpty($deleted['body']);

        $target = $this->getTarget($appId, $targetId);
        $this->assertSame(404, $target['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $target['body']['type']);

        $recreated = $this->createTarget($appId, ['platform' => 'android']);
        $this->assertSame(201, $recreated['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $again = $this->deleteTarget($appId, $targetId);
        $this->assertSame(404, $again['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $again['body']['type']);

        $missing = $this->deleteTarget(ID::unique(), $targetId);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTarget(string $appId, string $targetId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/targets/' . $targetId, $this->headers());
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function listTargets(string $appId, array $queries = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/targets', $this->headers(), [
            'queries' => $queries,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function updateTarget(string $appId, string $targetId, array $params): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId . '/targets/' . $targetId, $this->headers(), $params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteTarget(string $appId, string $targetId): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/targets/' . $targetId, $this->headers());
    }
}
