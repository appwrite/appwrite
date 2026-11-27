<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Utopia\Database\Query;

trait ReleasesBase
{
    public function testCreateRelease(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $version = '2.4.0+' . \uniqid();

        $created = $this->createRelease($appId, [
            'version' => $version,
            'notes' => ['en-US' => 'Bug fixes.', 'de-DE' => 'Fehlerbehebungen.'],
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertNotEmpty($created['body']['$id']);
        $this->assertSame($appId, $created['body']['appId']);
        $this->assertSame($version, $created['body']['version']);
        $this->assertSame(['en-US' => 'Bug fixes.', 'de-DE' => 'Fehlerbehebungen.'], $created['body']['notes']);
        $this->assertSame('draft', $created['body']['status']);

        $bare = $this->createRelease($appId);
        $this->assertSame(201, $bare['headers']['status-code']);
        $this->assertSame([], $bare['body']['notes']);

        $fetched = $this->getRelease($appId, $created['body']['$id']);
        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame($version, $fetched['body']['version']);

        $elsewhere = $this->createApp();
        $this->assertSame(201, $elsewhere['headers']['status-code']);
        $sameVersion = $this->createRelease($elsewhere['body']['$id'], ['version' => $version]);
        $this->assertSame(201, $sameVersion['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $duplicateVersion = $this->createRelease($appId, ['version' => $version]);
        $this->assertSame(409, $duplicateVersion['headers']['status-code']);
        $this->assertSame('distribution_release_already_exists', $duplicateVersion['body']['type']);

        $duplicateId = $this->createRelease($appId, ['releaseId' => $created['body']['$id']]);
        $this->assertSame(409, $duplicateId['headers']['status-code']);
        $this->assertSame('distribution_release_already_exists', $duplicateId['body']['type']);

        $long = $this->createRelease($appId, ['version' => \str_repeat('1', 65)]);
        $this->assertSame(400, $long['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $long['body']['type']);

        $missing = $this->createRelease(ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testListReleases(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        $first = $this->createRelease($appId);
        $second = $this->createRelease($appId);
        $this->assertSame(201, $first['headers']['status-code']);
        $this->assertSame(201, $second['headers']['status-code']);

        $other = $this->createApp();
        $this->assertSame(201, $other['headers']['status-code']);
        $this->assertSame(201, $this->createRelease($other['body']['$id'])['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $listed = $this->listReleases($appId);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertSame(2, $listed['body']['total']);
        $this->assertEqualsCanonicalizing(
            [$first['body']['$id'], $second['body']['$id']],
            \array_column($listed['body']['releases'], '$id'),
        );

        $filtered = $this->listReleases($appId, [Query::equal('version', [$second['body']['version']])->toString()]);
        $this->assertSame(200, $filtered['headers']['status-code']);
        $this->assertSame([$second['body']['$id']], \array_column($filtered['body']['releases'], '$id'));

        $drafts = $this->listReleases($appId, [Query::equal('status', ['draft'])->toString()]);
        $this->assertSame(200, $drafts['headers']['status-code']);
        $this->assertSame(2, $drafts['body']['total']);

        $limited = $this->listReleases($appId, [Query::limit(1)->toString()]);
        $this->assertSame(200, $limited['headers']['status-code']);
        $this->assertSame(2, $limited['body']['total']);
        $this->assertCount(1, $limited['body']['releases']);

        /**
         * Test for FAILURE
         */
        $unknown = $this->listReleases($appId, [Query::equal('listing', ['x'])->toString()]);
        $this->assertSame(400, $unknown['headers']['status-code']);

        $missing = $this->listReleases(ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testGetRelease(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->createRelease($appId);
        $this->assertSame(201, $created['headers']['status-code']);
        $releaseId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $release = $this->getRelease($appId, $releaseId);
        $this->assertSame(200, $release['headers']['status-code']);
        $this->assertSame($releaseId, $release['body']['$id']);
        $this->assertSame($created['body']['version'], $release['body']['version']);
        $this->assertSame('draft', $release['body']['status']);

        /**
         * Test for FAILURE
         */
        $other = $this->createApp();
        $this->assertSame(201, $other['headers']['status-code']);
        $foreign = $this->getRelease($other['body']['$id'], $releaseId);
        $this->assertSame(404, $foreign['headers']['status-code']);
        $this->assertSame('distribution_release_not_found', $foreign['body']['type']);

        $missing = $this->getRelease($appId, ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_release_not_found', $missing['body']['type']);
    }

    public function testUpdateRelease(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->createRelease($appId, ['notes' => ['en-US' => 'First.']]);
        $taken = $this->createRelease($appId);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame(201, $taken['headers']['status-code']);
        $releaseId = $created['body']['$id'];
        $version = '3.0.0+' . \uniqid();

        /**
         * Test for SUCCESS
         */
        $updated = $this->updateRelease($appId, $releaseId, [
            'version' => $version,
            'notes' => ['en-US' => 'Second.'],
        ]);
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame($version, $updated['body']['version']);
        $this->assertSame(['en-US' => 'Second.'], $updated['body']['notes']);
        $this->assertSame('draft', $updated['body']['status']);

        $fetched = $this->getRelease($appId, $releaseId);
        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame($version, $fetched['body']['version']);
        $this->assertSame(['en-US' => 'Second.'], $fetched['body']['notes']);

        /**
         * Test for FAILURE
         */
        $duplicate = $this->updateRelease($appId, $releaseId, ['version' => $taken['body']['version']]);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('distribution_release_already_exists', $duplicate['body']['type']);
        $this->assertSame($version, $this->getRelease($appId, $releaseId)['body']['version']);

        $missing = $this->updateRelease($appId, ID::unique(), ['version' => '9.9.9']);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_release_not_found', $missing['body']['type']);
    }

    public function testDeleteRelease(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $created = $this->createRelease($appId);
        $this->assertSame(201, $created['headers']['status-code']);
        $releaseId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $deleted = $this->deleteRelease($appId, $releaseId);
        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertEmpty($deleted['body']);

        $release = $this->getRelease($appId, $releaseId);
        $this->assertSame(404, $release['headers']['status-code']);
        $this->assertSame('distribution_release_not_found', $release['body']['type']);

        $reused = $this->createRelease($appId, ['version' => $created['body']['version']]);
        $this->assertSame(201, $reused['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $again = $this->deleteRelease($appId, $releaseId);
        $this->assertSame(404, $again['headers']['status-code']);
        $this->assertSame('distribution_release_not_found', $again['body']['type']);

        $missing = $this->deleteRelease(ID::unique(), $releaseId);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function listReleases(string $appId, array $queries = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/releases', $this->headers(), [
            'queries' => $queries,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function updateRelease(string $appId, string $releaseId, array $params): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId . '/releases/' . $releaseId, $this->headers(), $params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteRelease(string $appId, string $releaseId): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/releases/' . $releaseId, $this->headers());
    }
}
