<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideConsole;

class RealtimeConsoleClientTest extends Scope
{
    use RealtimeBase;
    use ProjectCustom;
    use SideConsole;

    public function testAttributes()
    {
        $user = $this->getUser();
        $projectId = 'console';

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        /**
         * Test Attributes
         */
        $actors = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'collectionId' => 'unique()',
            'name' => 'Actors',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'collection'
        ]);

        $actorsId = $actors['body']['$id'];

        $name = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actorsId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $attributeKey = $name['body']['key'];

        $this->assertEquals($name['headers']['status-code'], 201);
        $this->assertEquals($name['body']['key'], 'name');
        $this->assertEquals($name['body']['type'], 'string');
        $this->assertEquals($name['body']['size'], 256);
        $this->assertEquals($name['body']['required'], true);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("collections.{$actorsId}.attributes.{$actorsId}_{$attributeKey}.create", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.attributes.*.create", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.attributes.{$actorsId}_{$attributeKey}", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.attributes.*", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("collections.*.attributes.{$actorsId}_{$attributeKey}.create", $response['data']['events']);
        $this->assertContains("collections.*.attributes.*.create", $response['data']['events']);
        $this->assertContains("collections.*.attributes.{$actorsId}_{$attributeKey}", $response['data']['events']);
        $this->assertContains("collections.*.attributes.*", $response['data']['events']);
        $this->assertContains("collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('processing', $response['data']['payload']['status']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("collections.{$actorsId}.attributes.{$actorsId}_{$attributeKey}.update", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.attributes.*.update", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.attributes.{$actorsId}_{$attributeKey}", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.attributes.*", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("collections.*.attributes.{$actorsId}_{$attributeKey}.update", $response['data']['events']);
        $this->assertContains("collections.*.attributes.*.update", $response['data']['events']);
        $this->assertContains("collections.*.attributes.{$actorsId}_{$attributeKey}", $response['data']['events']);
        $this->assertContains("collections.*.attributes.*", $response['data']['events']);
        $this->assertContains("collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('available', $response['data']['payload']['status']);

        $client->close();

        $data = ['actorsId' => $actorsId];

        return $data;
    }

    /**
     * @depends testAttributes
     */
    public function testIndexes(array $data)
    {
        $projectId = 'console';
        $actorsId = $data['actorsId'];
        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        /**
         * Test Indexes
         */
        $index = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actorsId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'key_name',
            'type' => 'key',
            'attributes' => [
                'name',
            ],
        ]);

        $this->assertEquals($index['headers']['status-code'], 201);
        $indexKey = $index['body']['key'];

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("collections.{$actorsId}.indexes.{$actorsId}_{$indexKey}.create", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.indexes.*.create", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.indexes.{$actorsId}_{$indexKey}", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("collections.*.indexes.{$actorsId}_{$indexKey}.create", $response['data']['events']);
        $this->assertContains("collections.*.indexes.*.create", $response['data']['events']);
        $this->assertContains("collections.*.indexes.{$actorsId}_{$indexKey}", $response['data']['events']);
        $this->assertContains("collections.*.indexes.*", $response['data']['events']);
        $this->assertContains("collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('processing', $response['data']['payload']['status']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("collections.{$actorsId}.indexes.{$actorsId}_{$indexKey}.update", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.indexes.*.update", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.indexes.{$actorsId}_{$indexKey}", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("collections.*.indexes.{$actorsId}_{$indexKey}.update", $response['data']['events']);
        $this->assertContains("collections.*.indexes.*.update", $response['data']['events']);
        $this->assertContains("collections.*.indexes.{$actorsId}_{$indexKey}", $response['data']['events']);
        $this->assertContains("collections.*.indexes.*", $response['data']['events']);
        $this->assertContains("collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('available', $response['data']['payload']['status']);

        $client->close();

        return $data;
    }

    /**
     * @depends testIndexes
     */
    public function testDeleteIndex(array $data)
    {
        $actorsId = $data['actorsId'];
        $projectId = 'console';

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        /**
         * Test Delete Index
         */
        $attribute = $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $actorsId . '/indexes/key_name', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($attribute['headers']['status-code'], 204);
        $indexKey = 'key_name';
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("collections.{$actorsId}.indexes.{$actorsId}_{$indexKey}.delete", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.indexes.*.delete", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.indexes.{$actorsId}_{$indexKey}", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("collections.*.indexes.{$actorsId}_{$indexKey}.delete", $response['data']['events']);
        $this->assertContains("collections.*.indexes.*.delete", $response['data']['events']);
        $this->assertContains("collections.*.indexes.{$actorsId}_{$indexKey}", $response['data']['events']);
        $this->assertContains("collections.*.indexes.*", $response['data']['events']);
        $this->assertContains("collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();

        return $data;
    }

    /**
     * @depends testDeleteIndex
     */
    public function testDeleteAttribute(array $data)
    {
        $actorsId = $data['actorsId'];
        $projectId = 'console';

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ], $projectId);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertNotEmpty($response['data']['user']);

        /**
         * Test Delete Attribute
         */
        $attribute = $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $data['actorsId'] . '/attributes/name', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($attribute['headers']['status-code'], 204);
        $attributeKey = 'name';
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("collections.{$actorsId}.attributes.{$actorsId}_{$attributeKey}.delete", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.attributes.*.delete", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.attributes.{$actorsId}_{$attributeKey}", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}.attributes.*", $response['data']['events']);
        $this->assertContains("collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("collections.*.attributes.{$actorsId}_{$attributeKey}.delete", $response['data']['events']);
        $this->assertContains("collections.*.attributes.*.delete", $response['data']['events']);
        $this->assertContains("collections.*.attributes.{$actorsId}_{$attributeKey}", $response['data']['events']);
        $this->assertContains("collections.*.attributes.*", $response['data']['events']);
        $this->assertContains("collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();
    }
}
