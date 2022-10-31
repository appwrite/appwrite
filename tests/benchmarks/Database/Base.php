<?php

namespace Tests\Benchmarks\Database;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\ParamProviders;
use Tests\Benchmarks\Scope;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;

abstract class Base extends Scope
{
    use ProjectCustom;

    protected static string $databaseId;
    protected static string $collectionId;
    protected static string $documentId;

    #[BeforeMethods(['createDatabase', 'createCollection'])]
    public function benchDocumentCreate()
    {
        $this->client->call(Client::METHOD_POST, '/databases/' . static::$databaseId . '/collections/' . static::$collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => ID::unique(),
            'data' => [
                'title' => 'The Matrix',
            ],
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::write(Role::user($this->getUser()['$id'])),
            ],
        ]);
    }

    #[ParamProviders(['provideCounts'])]
    #[BeforeMethods(['createDatabase', 'createCollection', 'createDocuments'])]
    public function benchDocumentReadList(array $params)
    {
        $this->client->call(Client::METHOD_GET, '/databases/' . static::$databaseId . '/collections/' . static::$collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['limit(' . $params['documents'] . ')'],
        ]);
    }

    #[BeforeMethods(['createDatabase', 'createCollection', 'createDocuments'])]
    public function benchDocumentRead()
    {
        $this->client->call(Client::METHOD_GET, '/databases/' . static::$databaseId . '/collections/' . static::$collectionId . '/documents/' . static::$documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));
    }

    #[BeforeMethods(['createDatabase', 'createCollection', 'createDocuments'])]
    public function benchDocumentUpdate()
    {
        $this->client->call(Client::METHOD_PATCH, '/databases/' . static::$databaseId . '/collections/' . static::$collectionId . '/documents/' . static::$documentId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'The Matrix Reloaded',
            ],
        ]);
    }

    public function provideCounts(): array
    {
        return [
            '1 Document' => ['documents' => 1],
            '10 Documents' => ['documents' => 10],
            '100 Documents' => ['documents' => 100],
        ];
    }

    public function createDatabase(array $params = [])
    {
        // Create database
        $database = $this->client->call(Client::METHOD_POST, '/databases', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'databaseId' => ID::unique(),
            'name' => 'Test Database'
        ]);
        static::$databaseId = $database['body']['$id'];
    }

    public function createCollection(array $params = [])
    {
        // Create collection
        $movies = $this->client->call(Client::METHOD_POST, '/databases/' . static::$databaseId . '/collections', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'collectionId' => ID::unique(),
            'name' => 'Movies',
            'documentSecurity' => true,
            'permissions' => [
                Permission::read(Role::user($this->getUser()['$id'])),
                Permission::write(Role::user($this->getUser()['$id'])),
            ],
        ]);
        static::$collectionId = $movies['body']['$id'];

        // Create attribute
        $this->client->call(Client::METHOD_POST, '/databases/' . static::$databaseId . '/collections/' . static::$collectionId . '/attributes/string', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        sleep(2);
    }

    public function createDocuments(array $params = [])
    {
        $count = $params['documents'] ?? 1;

        // Create documents
        for ($i = 0; $i < $count; $i++) {
            $response = $this->client->call(Client::METHOD_POST, '/databases/' . static::$databaseId . '/collections/' . static::$collectionId . '/documents', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ], [
                'documentId' => ID::unique(),
                'data' => [
                    'title' => 'Captain America' . $i,
                ],
                'permissions' => [
                    Permission::read(Role::user($this->getUser()['$id'])),
                    Permission::write(Role::user($this->getUser()['$id'])),
                ]
            ]);

            static::$documentId = $response['body']['$id'];
        }
    }
}
