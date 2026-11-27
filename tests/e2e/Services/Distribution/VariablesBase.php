<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Query;

trait VariablesBase
{
    public function testCreateVariable(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $secret = $this->createVariable($appId, ['key' => 'API_KEY', 'value' => 'hidden']);
        $this->assertSame(201, $secret['headers']['status-code']);
        $this->assertNotEmpty($secret['body']['$id']);
        $this->assertSame('API_KEY', $secret['body']['key']);
        $this->assertSame('distributionApps', $secret['body']['resourceType']);
        $this->assertSame($appId, $secret['body']['resourceId']);
        $this->assertTrue($secret['body']['secret']);
        $this->assertEmpty($secret['body']['value']);
        $this->assertSame('', $secret['body']['description']);

        $plain = $this->createVariable($appId, [
            'key' => 'flavor',
            'value' => 'production',
            'secret' => false,
            'description' => 'Build flavor.',
        ]);
        $this->assertSame(201, $plain['headers']['status-code']);
        $this->assertFalse($plain['body']['secret']);
        $this->assertSame('production', $plain['body']['value']);
        $this->assertSame('Build flavor.', $plain['body']['description']);

        $other = $this->createApp();
        $this->assertSame(201, $other['headers']['status-code']);
        $reused = $this->createVariable($other['body']['$id'], ['key' => 'API_KEY']);
        $this->assertSame(201, $reused['headers']['status-code']);
        $this->assertSame($other['body']['$id'], $reused['body']['resourceId']);

        /**
         * Test for FAILURE
         */
        $duplicate = $this->createVariable($appId, ['key' => 'API_KEY']);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('variable_already_exists', $duplicate['body']['type']);

        foreach (['DISTRIBUTION_FOO', 'appwrite_x', '1KEY'] as $key) {
            $invalid = $this->createVariable($appId, ['key' => $key]);
            $this->assertSame(400, $invalid['headers']['status-code'], $key);
            $this->assertSame('general_argument_invalid', $invalid['body']['type'], $key);
        }

        $missing = $this->createVariable(ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testListVariables(): void
    {
        $app = $this->createApp();
        $other = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $this->assertSame(201, $other['headers']['status-code']);
        $appId = $app['body']['$id'];

        $first = $this->createVariable($appId, ['key' => 'FIRST']);
        $second = $this->createVariable($appId, ['key' => 'SECOND']);
        $foreign = $this->createVariable($other['body']['$id'], ['key' => 'FIRST']);
        $this->assertSame(201, $first['headers']['status-code']);
        $this->assertSame(201, $second['headers']['status-code']);
        $this->assertSame(201, $foreign['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $list = $this->listVariables($appId);
        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertSame(2, $list['body']['total']);
        $this->assertSame(
            [$first['body']['$id'], $second['body']['$id']],
            \array_column($list['body']['variables'], '$id'),
        );

        $filtered = $this->listVariables($appId, [Query::equal('key', ['SECOND'])->toString()]);
        $this->assertSame(200, $filtered['headers']['status-code']);
        $this->assertSame(1, $filtered['body']['total']);
        $this->assertSame([$second['body']['$id']], \array_column($filtered['body']['variables'], '$id'));

        $paged = $this->listVariables($appId, [
            Query::cursorAfter(new Document(['$id' => $first['body']['$id']]))->toString(),
        ]);
        $this->assertSame(200, $paged['headers']['status-code']);
        $this->assertSame([$second['body']['$id']], \array_column($paged['body']['variables'], '$id'));

        $untotaled = $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/variables', $this->headers(), [
            'total' => false,
        ]);
        $this->assertSame(200, $untotaled['headers']['status-code']);
        $this->assertSame(0, $untotaled['body']['total']);
        $this->assertCount(2, $untotaled['body']['variables']);

        /**
         * Test for FAILURE
         */
        $cursor = $this->listVariables($appId, [
            Query::cursorAfter(new Document(['$id' => $foreign['body']['$id']]))->toString(),
        ]);
        $this->assertSame(400, $cursor['headers']['status-code']);
        $this->assertSame('general_cursor_not_found', $cursor['body']['type']);

        $unknown = $this->listVariables($appId, [Query::equal('value', ['x'])->toString()]);
        $this->assertSame(400, $unknown['headers']['status-code']);

        $missing = $this->listVariables(ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testGetVariable(): void
    {
        $app = $this->createApp();
        $other = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $this->assertSame(201, $other['headers']['status-code']);
        $appId = $app['body']['$id'];

        $created = $this->createVariable($appId, ['key' => 'VISIBLE', 'value' => 'shown', 'secret' => false]);
        $this->assertSame(201, $created['headers']['status-code']);
        $variableId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $variable = $this->getVariable($appId, $variableId);
        $this->assertSame(200, $variable['headers']['status-code']);
        $this->assertSame($variableId, $variable['body']['$id']);
        $this->assertSame('VISIBLE', $variable['body']['key']);
        $this->assertSame('shown', $variable['body']['value']);

        /**
         * Test for FAILURE
         */
        $foreign = $this->getVariable($other['body']['$id'], $variableId);
        $this->assertSame(404, $foreign['headers']['status-code']);
        $this->assertSame('variable_not_found', $foreign['body']['type']);

        $missing = $this->getVariable($appId, ID::unique());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('variable_not_found', $missing['body']['type']);

        $app = $this->getVariable(ID::unique(), $variableId);
        $this->assertSame(404, $app['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $app['body']['type']);
    }

    public function testUpdateVariable(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        $created = $this->createVariable($appId, ['key' => 'TOKEN', 'value' => 'old']);
        $taken = $this->createVariable($appId, ['key' => 'TAKEN', 'secret' => false]);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame(201, $taken['headers']['status-code']);
        $variableId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $updated = $this->updateVariable($appId, $variableId, ['value' => 'new', 'description' => 'Rotated.']);
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('TOKEN', $updated['body']['key']);
        $this->assertSame('Rotated.', $updated['body']['description']);
        $this->assertTrue($updated['body']['secret']);
        $this->assertEmpty($updated['body']['value']);

        $renamed = $this->updateVariable($appId, $variableId, ['key' => 'RENAMED']);
        $this->assertSame(200, $renamed['headers']['status-code']);
        $this->assertSame('RENAMED', $renamed['body']['key']);
        $this->assertSame('Rotated.', $renamed['body']['description']);

        $secured = $this->updateVariable($appId, $taken['body']['$id'], ['secret' => true]);
        $this->assertSame(200, $secured['headers']['status-code']);
        $this->assertTrue($secured['body']['secret']);

        /**
         * Test for FAILURE
         */
        $reserved = $this->updateVariable($appId, $variableId, ['key' => 'appwrite_TOKEN']);
        $this->assertSame(400, $reserved['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $reserved['body']['type']);

        $duplicate = $this->updateVariable($appId, $variableId, ['key' => 'TAKEN']);
        $this->assertSame(409, $duplicate['headers']['status-code']);
        $this->assertSame('variable_already_exists', $duplicate['body']['type']);

        $unset = $this->updateVariable($appId, $variableId, ['secret' => false]);
        $this->assertSame(400, $unset['headers']['status-code']);
        $this->assertSame('variable_cannot_unset_secret', $unset['body']['type']);

        $empty = $this->updateVariable($appId, $variableId, []);
        $this->assertSame(400, $empty['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $empty['body']['type']);

        $missing = $this->updateVariable($appId, ID::unique(), ['value' => 'x']);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('variable_not_found', $missing['body']['type']);

        $this->assertSame('RENAMED', $this->getVariable($appId, $variableId)['body']['key']);
    }

    public function testDeleteVariable(): void
    {
        $app = $this->createApp();
        $other = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $this->assertSame(201, $other['headers']['status-code']);
        $appId = $app['body']['$id'];

        $created = $this->createVariable($appId, ['key' => 'DOOMED']);
        $this->assertSame(201, $created['headers']['status-code']);
        $variableId = $created['body']['$id'];

        /**
         * Test for FAILURE
         */
        $foreign = $this->deleteVariable($other['body']['$id'], $variableId);
        $this->assertSame(404, $foreign['headers']['status-code']);
        $this->assertSame('variable_not_found', $foreign['body']['type']);

        /**
         * Test for SUCCESS
         */
        $deleted = $this->deleteVariable($appId, $variableId);
        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertEmpty($deleted['body']);

        $gone = $this->getVariable($appId, $variableId);
        $this->assertSame(404, $gone['headers']['status-code']);
        $this->assertSame('variable_not_found', $gone['body']['type']);

        $again = $this->deleteVariable($appId, $variableId);
        $this->assertSame(404, $again['headers']['status-code']);
        $this->assertSame('variable_not_found', $again['body']['type']);

        $recreated = $this->createVariable($appId, ['key' => 'DOOMED']);
        $this->assertSame(201, $recreated['headers']['status-code']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createVariable(string $appId, array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/variables', $this->headers(), \array_merge([
            'variableId' => ID::unique(),
            'key' => 'KEY_' . \strtoupper(\uniqid()),
            'value' => 'value',
        ], $params));
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function listVariables(string $appId, array $queries = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/variables', $this->headers(), [
            'queries' => $queries,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getVariable(string $appId, string $variableId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/variables/' . $variableId, $this->headers());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function updateVariable(string $appId, string $variableId, array $params): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId . '/variables/' . $variableId, $this->headers(), $params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteVariable(string $appId, string $variableId): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/variables/' . $variableId, $this->headers());
    }
}
