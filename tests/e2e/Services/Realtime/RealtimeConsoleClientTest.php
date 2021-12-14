<?php

namespace Tests\E2E\Services\Realtime;

use Exception;
use SebastianBergmann\RecursionContext\InvalidArgumentException;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\Exception as FrameworkException;
use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideConsole;
use WebSocket\BadOpcodeException;
use WebSocket\ConnectionException;
use WebSocket\TimeoutException;

class RealtimeConsoleClientTest extends Scope
{
    use RealtimeBase;
    use ProjectCustom;
    use SideConsole;

    public function testAttributes()
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = 'console';

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console='. $this->getRoot()['session'],
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

        $data = ['actorsId' => $actors['body']['$id']];

        $name = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'attributeId' => 'name',
            'size' => 256,
            'required' => true,
        ]);

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
        $this->assertEquals('database.attributes.create', $response['data']['event']);
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
        $this->assertEquals('database.attributes.update', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('available', $response['data']['payload']['status']);

        $client->close();

        return $data;
    }

    /**
     * @depends testAttributes
     */
    public function testIndexes(array $data)
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = 'console';

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console='. $this->getRoot()['session'],
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
        $index = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'indexId' => 'key_name',
            'type' => 'key',
            'attributes' => [
                'name',
            ],
        ]);

        $this->assertEquals($index['headers']['status-code'], 201);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertEquals('database.indexes.create', $response['data']['event']);
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
        $this->assertEquals('database.indexes.update', $response['data']['event']);
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
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = 'console';

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console='. $this->getRoot()['session'],
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
        $attribute = $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $data['actorsId'] . '/indexes/key_name', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($attribute['headers']['status-code'], 204);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertEquals('database.indexes.delete', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();

        return $data;
    }

    /**
     * @depends testDeleteIndex
     */
    public function testDeleteAttribute(array $data)
    {
        $user = $this->getUser();
        $session = $user['session'] ?? '';
        $projectId = 'console';

        $client = $this->getWebsocket(['console'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console='. $this->getRoot()['session'],
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

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertEquals('database.attributes.delete', $response['data']['event']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();
    }
}