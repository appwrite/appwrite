<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;

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
            'databaseId' => ID::unique(),
            'name' => 'InvalidDocumentDatabase',
        ]);
        $this->assertEquals(201, $database['headers']['status-code']);
        $this->assertEquals('InvalidDocumentDatabase', $database['body']['name']);

        $databaseId = $database['body']['$id'];
        $movies = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections', $this->getServerHeader(), [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'documentSecurity' => true,
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
     * [string[] $permissions]
     */
    public function readDocumentsProvider()
    {
        return [
            [[Permission::read(Role::any())]],
            [[Permission::read(Role::users())]],
            [[Permission::update(Role::any()), Permission::delete(Role::any())]],
            [[Permission::read(Role::any()), Permission::update(Role::any()), Permission::delete(Role::any())]],
            [[Permission::read(Role::users()), Permission::update(Role::users()), Permission::delete(Role::users())]],
            [[Permission::read(Role::any()), Permission::update(Role::users()), Permission::delete(Role::users())]],
        ];
    }

    /**
     * @dataProvider readDocumentsProvider
     */
    public function testReadDocuments($permissions)
    {
        $data = $this->createCollection();
        $collectionId = $data['collectionId'];
        $databaseId = $data['databaseId'];
        $response = $this->client->call(Client::METHOD_POST, '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', $this->getServerHeader(), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'Lorem',
            ],
            'permissions' => $permissions,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $documents = $this->client->call(Client::METHOD_GET, '/databases/' . $databaseId . '/collections/' . $collectionId  . '/documents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        foreach ($documents['body']['documents'] as $document) {
            foreach ($document['$permissions'] as $permission) {
                if (!\str_starts_with($permission, 'read')) {
                     continue;
                }
                $this->assertTrue(\str_contains($permission, 'any'));
            }
        }
    }
}
