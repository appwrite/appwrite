<?php

namespace Tests\E2E\Services\Realtime;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideConsole;
use Tests\E2E\Services\Functions\FunctionsBase;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class RealtimeConsoleClientTest extends Scope
{
    use FunctionsBase;
    use RealtimeBase;
    use ProjectCustom;
    use SideConsole;

    public function testManualAuthentication()
    {
        $user = $this->getUser();
        $userId = $user['$id'] ?? '';
        $session = $user['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $client = $this->getWebsocket(['account'], [
            'origin' => 'http://localhost'
        ]);
        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('connected', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('account', $response['data']['channels']);

        $client->send(\json_encode([
            'type' => 'authentication',
            'data' => [
                'session' => $session
            ]
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('response', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals('authentication', $response['data']['to']);
        $this->assertTrue($response['data']['success']);
        $this->assertNotEmpty($response['data']['user']);
        $this->assertEquals($userId, $response['data']['user']['$id']);

        /**
         * Test for FAILURE
         */
        $client->send(\json_encode([
            'type' => 'authentication',
            'data' => [
                'session' => 'invalid_session'
            ]
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Session is not valid.', $response['data']['message']);

        $client->send(\json_encode([
            'type' => 'authentication',
            'data' => []
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Payload is not valid.', $response['data']['message']);

        $client->send(\json_encode([
            'type' => 'unknown',
            'data' => [
                'session' => 'invalid_session'
            ]
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Message type is not valid.', $response['data']['message']);

        $client->send(\json_encode([
            'test' => '123',
        ]));

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('error', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertEquals(1003, $response['data']['code']);
        $this->assertEquals('Message format is not valid.', $response['data']['message']);


        $client->close();
    }

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
         * Create database
         */
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'databaseId' => ID::unique(),
            'name' => 'Actors DB',
        ]);

        $databaseId = $database['body']['$id'];
        /**
         * Test Attributes
         */
        $actors = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'collectionId' => ID::unique(),
            'name' => 'Actors',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);

        $actorsId = $actors['body']['$id'];

        $name = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);

        $attributeKey = $name['body']['key'];

        $this->assertEquals($name['headers']['status-code'], 202);
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
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
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
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}", $response['data']['events']);
        $this->assertContains("databases.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);
        $this->assertEquals('available', $response['data']['payload']['status']);

        $client->close();

        $data = ['actorsId' => $actorsId, 'databaseId' => $databaseId];

        return $data;
    }

    /**
     * @depends testAttributes
     */
    public function testIndexes(array $data)
    {
        $projectId = 'console';
        $actorsId = $data['actorsId'];
        $databaseId = $data['databaseId'];
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
        $index = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $actorsId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'key' => 'key_name',
            'type' => 'key',
            'attributes' => [
                'name',
            ],
        ]);

        $this->assertEquals($index['headers']['status-code'], 202);
        $indexKey = $index['body']['key'];

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*.create", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
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
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*.update", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
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
        $databaseId = $data['databaseId'];

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
        $attribute = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $actorsId . '/indexes/key_name', array_merge([
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
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.indexes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
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
        $databaseId = $data['databaseId'];

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
        $attribute = $this->client->call(Client::METHOD_DELETE, '/databases/' . $databaseId . '/collections/' . $data['actorsId'] . '/attributes/name', array_merge([
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
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.{$actorsId}", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*.delete", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*.attributes.*", $response['data']['events']);
        $this->assertContains("databases.{$databaseId}.collections.*", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();
    }

    public function testCreateDeployment()
    {
        $response1 = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Test',
            'runtime' => 'php-8.0',
            'events' => [
                'users.*.create',
                'users.*.delete',
            ],
            'schedule' => '0 0 1 1 *',
            'timeout' => 10
        ]);

        $functionId = $response1['body']['$id'] ?? '';

        $this->assertEquals(201, $response1['headers']['status-code']);


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
         * Test Create Deployment
         */

        $folder = 'php';
        $code = realpath(__DIR__ . '/../../../resources/functions') . "/$folder/code.tar.gz";
        $this->packageCode($folder);

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'entrypoint' => 'index.php',
            'code' => new CURLFile($code, 'application/x-gzip', \basename($code)),
            'activate' => true
        ]);

        $deploymentId = $deployment['body']['$id'] ?? '';

        $this->assertEquals(202, $deployment['headers']['status-code']);

        $response = json_decode($client->receive(), true);

        $this->assertArrayHasKey('type', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals('event', $response['type']);
        $this->assertNotEmpty($response['data']);
        $this->assertArrayHasKey('timestamp', $response['data']);
        $this->assertCount(1, $response['data']['channels']);
        $this->assertContains('console', $response['data']['channels']);
        $this->assertContains("functions.{$functionId}.deployments.{$deploymentId}.create", $response['data']['events']);
        $this->assertNotEmpty($response['data']['payload']);

        $client->close();
    }
}
