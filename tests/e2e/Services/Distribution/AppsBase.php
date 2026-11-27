<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Query;

trait AppsBase
{
    public function testCreateApp(): void
    {
        $name = 'Created ' . \uniqid();

        $created = $this->createApp(['name' => $name, 'framework' => 'react-native']);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertNotEmpty($created['body']['$id']);
        $this->assertSame($name, $created['body']['name']);
        $this->assertSame('react-native', $created['body']['framework']);
        $this->assertTrue($created['body']['enabled']);
        $this->assertTrue($created['body']['providerComments']);
        $this->assertTrue($created['body']['providerDeployments']);
        $this->assertTrue($created['body']['providerStatuses']);
        $this->assertFalse($created['body']['providerSilentMode']);
        $this->assertSame('', $created['body']['latestBuildStatus']);
        $this->assertSame('', $created['body']['repositoryId']);

        $disabled = $this->createApp(['enabled' => false]);
        $this->assertSame(201, $disabled['headers']['status-code']);
        $this->assertFalse($disabled['body']['enabled']);

        $fetched = $this->getApp($created['body']['$id']);
        $this->assertSame(200, $fetched['headers']['status-code']);
        $this->assertSame($name, $fetched['body']['name']);

        /**
         * Test for FAILURE
         */
        $framework = $this->createApp(['framework' => 'xamarin']);
        $this->assertSame(400, $framework['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $framework['body']['type']);

        $duplicate = $this->createApp(['appId' => $created['body']['$id']]);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('distribution_app_already_exists', $duplicate['body']['type']);
    }

    public function testListApps(): void
    {
        $token = \uniqid('apps');
        $first = $this->createApp(['name' => $token, 'framework' => 'flutter']);
        $second = $this->createApp(['name' => $token, 'framework' => 'android']);
        $this->assertSame(201, $first['headers']['status-code']);
        $this->assertSame(201, $second['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $searched = $this->listApps([Query::search('search', $token)->toString()]);
        $this->assertSame(200, $searched['headers']['status-code']);
        $this->assertSame(2, $searched['body']['total']);
        $this->assertCount(2, $searched['body']['apps']);

        $filtered = $this->listApps([
            Query::search('search', $token)->toString(),
            Query::equal('framework', ['android'])->toString(),
        ]);
        $this->assertSame(200, $filtered['headers']['status-code']);
        $this->assertSame([$second['body']['$id']], \array_column($filtered['body']['apps'], '$id'));

        $paged = $this->listApps([
            Query::search('search', $token)->toString(),
            Query::orderAsc('$sequence')->toString(),
            Query::cursorAfter(new Document(['$id' => $first['body']['$id']]))->toString(),
        ]);
        $this->assertSame(200, $paged['headers']['status-code']);
        $this->assertSame([$second['body']['$id']], \array_column($paged['body']['apps'], '$id'));

        $untotaled = $this->client->call(Client::METHOD_GET, '/distribution/apps', $this->headers(), [
            'queries' => [Query::search('search', $token)->toString()],
            'total' => false,
        ]);
        $this->assertSame(200, $untotaled['headers']['status-code']);
        $this->assertSame(0, $untotaled['body']['total']);
        $this->assertCount(2, $untotaled['body']['apps']);

        /**
         * Test for FAILURE
         */
        $unknown = $this->listApps([Query::equal('providerRootDirectory', ['apps/mobile'])->toString()]);
        $this->assertSame(400, $unknown['headers']['status-code']);

        $cursor = $this->listApps([Query::cursorAfter(new Document(['$id' => 'missing']))->toString()]);
        $this->assertSame(400, $cursor['headers']['status-code']);
        $this->assertSame('general_cursor_not_found', $cursor['body']['type']);
    }

    public function testGetApp(): void
    {
        $created = $this->createApp();
        $this->assertSame(201, $created['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $app = $this->getApp($created['body']['$id']);
        $this->assertSame(200, $app['headers']['status-code']);
        $this->assertSame($created['body']['$id'], $app['body']['$id']);
        $this->assertSame($created['body']['name'], $app['body']['name']);
        $this->assertSame('flutter', $app['body']['framework']);

        /**
         * Test for FAILURE
         */
        $missing = $this->getApp(ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testUpdateApp(): void
    {
        $created = $this->createApp(['framework' => 'flutter']);
        $this->assertSame(201, $created['headers']['status-code']);
        $appId = $created['body']['$id'];
        $name = 'Renamed ' . \uniqid();

        /**
         * Test for SUCCESS
         */
        $renamed = $this->updateApp($appId, ['name' => $name]);
        $this->assertSame(200, $renamed['headers']['status-code']);
        $this->assertSame($name, $renamed['body']['name']);
        $this->assertSame('flutter', $renamed['body']['framework']);
        $this->assertTrue($renamed['body']['enabled']);

        $searched = $this->listApps([Query::search('search', $name)->toString()]);
        $this->assertContains($appId, \array_column($searched['body']['apps'], '$id'));

        $disabled = $this->updateApp($appId, ['enabled' => false]);
        $this->assertSame(200, $disabled['headers']['status-code']);
        $this->assertFalse($disabled['body']['enabled']);
        $this->assertSame($name, $disabled['body']['name']);

        $target = $this->createTarget($appId, ['platform' => 'android']);
        $this->assertSame(201, $target['headers']['status-code']);

        $switched = $this->updateApp($appId, ['framework' => 'android']);
        $this->assertSame(200, $switched['headers']['status-code']);
        $this->assertSame('android', $switched['body']['framework']);

        /**
         * Test for FAILURE
         */
        $empty = $this->updateApp($appId, []);
        $this->assertSame(400, $empty['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $empty['body']['type']);

        $unsupported = $this->updateApp($appId, ['framework' => 'ios']);
        $this->assertSame(400, $unsupported['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $unsupported['body']['type']);
        $this->assertSame('android', $this->getApp($appId)['body']['framework']);

        $framework = $this->updateApp($appId, ['framework' => 'xamarin']);
        $this->assertSame(400, $framework['headers']['status-code']);

        $missing = $this->updateApp(ID::unique(), ['name' => 'Missing']);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testDeleteApp(): void
    {
        $appId = ID::unique();
        $targetId = ID::unique();
        $variableId = ID::unique();
        $key = 'KEY_' . \strtoupper(\uniqid());

        $created = $this->createApp(['appId' => $appId]);
        $this->assertSame(201, $created['headers']['status-code']);
        $target = $this->createTarget($appId, ['targetId' => $targetId]);
        $this->assertSame(201, $target['headers']['status-code']);
        $variable = $this->createAppVariable($appId, $variableId, $key);
        $this->assertSame(201, $variable['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $deleted = $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId, $this->headers());
        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertEmpty($deleted['body']);

        $app = $this->getApp($appId);
        $this->assertSame(404, $app['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $app['body']['type']);

        /**
         * Test for FAILURE
         */
        $missing = $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId, $this->headers());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);

        /**
         * Test for SUCCESS
         */
        $recreated = $this->createApp(['appId' => $appId]);
        $this->assertSame(201, $recreated['headers']['status-code']);

        $recreatedTarget = $this->createTarget($appId, ['targetId' => $targetId]);
        $this->assertSame(201, $recreatedTarget['headers']['status-code'], 'The deleted app left its target behind.');

        $recreatedVariable = $this->createAppVariable($appId, $variableId, $key);
        $this->assertSame(201, $recreatedVariable['headers']['status-code'], 'The deleted app left its variable behind.');
    }

    /**
     * @return array<string, mixed>
     */
    private function createAppVariable(string $appId, string $variableId, string $key): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/variables', $this->headers(), [
            'variableId' => $variableId,
            'key' => $key,
            'value' => 'value',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getApp(string $appId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId, $this->headers());
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function listApps(array $queries = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps', $this->headers(), [
            'queries' => $queries,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function updateApp(string $appId, array $params): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId, $this->headers(), $params);
    }
}
