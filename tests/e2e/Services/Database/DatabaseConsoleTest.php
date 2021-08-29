<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Client;
use Tests\E2E\Scopes\SideConsole;

class DatabaseConsoleTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testCreateCollection():array
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
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/database/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['documents.count']);
        $this->assertIsArray($response['body']['collections.count']);
        $this->assertIsArray($response['body']['documents.create']);
        $this->assertIsArray($response['body']['documents.read']);
        $this->assertIsArray($response['body']['documents.update']);
        $this->assertIsArray($response['body']['documents.delete']);
        $this->assertIsArray($response['body']['collections.create']);
        $this->assertIsArray($response['body']['collections.read']);
        $this->assertIsArray($response['body']['collections.update']);
        $this->assertIsArray($response['body']['collections.delete']);
    }


    /**
     * @depends testCreateCollection
     */
    public function testGetCollectionUsage(array $data)
    {
        /**
         * Test for SUCCESS
         */

        $response = $this->client->call(Client::METHOD_GET, '/database/'.$data['moviesId'].'/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()), [
            'range' => '24h'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['range'], '24h');
        $this->assertIsArray($response['body']['documents.count']);
        $this->assertIsArray($response['body']['documents.create']);
        $this->assertIsArray($response['body']['documents.read']);
        $this->assertIsArray($response['body']['documents.update']);
        $this->assertIsArray($response['body']['documents.delete']);
    }
}