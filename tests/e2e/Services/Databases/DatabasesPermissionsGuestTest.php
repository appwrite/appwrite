<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class DatabasesPermissionsGuestTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use DatabasesPermissionsScope;

    public function createCollection(): array
    {
        $database = $this->client->call(Client::METHOD_POST, '/databases', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'databaseId' => 'unique()',
            'name' => 'InvalidDocumentDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('InvalidDocumentDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $movies = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => 'unique()',
            'name' => 'Movies',
            'read' => ['role:all'],
            'write' => ['role:all'],
            'permission' => 'document',
        ]);

        $collection = ['id' => $movies['body']['$id']];

        $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collection['id'] . '/attributes/string', $this->getServerHeader(), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);

        return ['collectionId' => $collection['id'], 'databaseId' => $databaseId];
    }

    /**
     * [string[] $read, string[] $write]
     */
    public function readDocumentsProvider()
    {
        return [
            [['role:all'], []],
            [['role:member'], []],
            [[] ,['role:all']],
            [['role:all'], ['role:all']],
            [['role:member'], ['role:member']],
            [['role:all'], ['role:member']],
        ];
    }

    /**
     * @dataProvider readDocumentsProvider
     */
    public function testReadDocuments($read, $write)
    {
        $data = $this->createCollection();
        $collectionId = $data['collectionId'];
        $databaseId = $data['databaseId'];
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', $this->getServerHeader(), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Lorem',
            ],
            'read' => $read,
            'write' => $write,
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId  . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        foreach ($documents['body']['documents'] as $document) {
            $this->assertContains('role:all', $document['$read']);
        }
    }
}
