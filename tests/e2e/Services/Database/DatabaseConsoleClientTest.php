<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Client;
use Tests\E2E\Scopes\SideConsole;

class DatabaseConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testCreateCollection(): array
    {
        /**
         * Test for SUCCESS
         */
        $movies = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'collectionId' => 'unique()',
            'name' => 'Movies',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document',
        ]);

        $this->assertEquals($movies['headers']['status-code'], 201);
        $this->assertEquals($movies['body']['name'], 'Movies');

        return ['moviesId' => $movies['body']['$id']];
    }

    public function testGetDatabaseUsage()
    {
        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/database/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/database/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(count($response['body']), 11);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['documentsCount']);
        $this->assertIsArray($response['body']['collectionsCount']);
        $this->assertIsArray($response['body']['documentsCreate']);
        $this->assertIsArray($response['body']['documentsRead']);
        $this->assertIsArray($response['body']['documentsUpdate']);
        $this->assertIsArray($response['body']['documentsDelete']);
        $this->assertIsArray($response['body']['collectionsCreate']);
        $this->assertIsArray($response['body']['collectionsRead']);
        $this->assertIsArray($response['body']['collectionsUpdate']);
        $this->assertIsArray($response['body']['collectionsDelete']);
    }


    /**
     * @depends testCreateCollection
     */
    public function testGetCollectionUsage(array $data)
    {
        /**
         * Test for FAILURE
         */

        $response = $this->client->call(Client::METHOD_GET, '/database/' . $data['moviesId'] . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '32h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 400);

        $response = $this->client->call(Client::METHOD_GET, '/database/randomCollectionId/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 404);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/database/' . $data['moviesId'] . '/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(count($response['body']), 6);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['documentsCount']);
        $this->assertIsArray($response['body']['documentsCreate']);
        $this->assertIsArray($response['body']['documentsRead']);
        $this->assertIsArray($response['body']['documentsUpdate']);
        $this->assertIsArray($response['body']['documentsDelete']);
    }
}
