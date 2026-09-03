<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Legacy;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

/**
 * The legacy /v1/databases surface is gated on collections.* / attributes.* / documents.*,
 * all flagged deprecated in app/config/scopes/project.php. The console hides deprecated
 * scopes from the key editor, so a key created today carries only the current Databases
 * scopes and cannot reach the legacy routes at all.
 */
final class DatabasesKeyScopesTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    /**
     * Every project scope in the Databases category that is not flagged deprecated,
     * i.e. exactly what a key created from the console today can carry.
     */
    private const CURRENT_SCOPES = [
        'databases.read',
        'databases.write',
        'tables.read',
        'tables.write',
        'columns.read',
        'columns.write',
        'indexes.read',
        'indexes.write',
        'rows.read',
        'rows.write',
        'embeddings.write',
        'documentsdb.read',
        'documentsdb.write',
        'documentsdb.collections.read',
        'documentsdb.collections.write',
        'documentsdb.documents.read',
        'documentsdb.documents.write',
        'documentsdb.indexes.read',
        'documentsdb.indexes.write',
        'vectorsdb.read',
        'vectorsdb.write',
        'vectorsdb.collections.read',
        'vectorsdb.collections.write',
        'vectorsdb.documents.read',
        'vectorsdb.documents.write',
        'vectorsdb.indexes.read',
        'vectorsdb.indexes.write',
    ];

    public function testUpdateCollectionWithCurrentScopes(): void
    {
        [$databaseId, $collectionId] = $this->createCollection();
        $headers = $this->getCurrentScopedHeaders();

        $response = $this->client->call(
            Client::METHOD_PUT,
            '/databases/' . $databaseId . '/collections/' . $collectionId,
            $headers,
            [
                'name' => 'Renamed',
                'permissions' => [],
            ]
        );

        $this->assertSame(200, $response['headers']['status-code'], 'Legacy update collection rejected: ' . ($response['body']['message'] ?? ''));
        $this->assertSame('Renamed', $response['body']['name']);
    }

    public function testCreateDocumentWithCurrentScopes(): void
    {
        [$databaseId, $collectionId] = $this->createCollection();
        $headers = $this->getCurrentScopedHeaders();

        $response = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
            $headers,
            [
                'documentId' => ID::unique(),
                'data' => ['title' => 'Hello'],
            ]
        );

        $this->assertSame(201, $response['headers']['status-code'], 'Legacy create document rejected: ' . ($response['body']['message'] ?? ''));
    }

    public function testUpdateTableWithCurrentScopes(): void
    {
        [$databaseId, $collectionId] = $this->createCollection();
        $headers = $this->getCurrentScopedHeaders();

        $response = $this->client->call(
            Client::METHOD_PUT,
            '/tablesdb/' . $databaseId . '/tables/' . $collectionId,
            $headers,
            [
                'name' => 'Renamed',
                'permissions' => [],
            ]
        );

        $this->assertSame(200, $response['headers']['status-code'], 'TablesDB update table rejected: ' . ($response['body']['message'] ?? ''));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function createCollection(): array
    {
        $headers = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $database = $this->client->call(Client::METHOD_POST, '/databases', $headers, [
            'databaseId' => ID::unique(),
            'name' => 'Scopes',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);

        $collection = $this->client->call(Client::METHOD_POST, '/databases/' . $database['body']['$id'] . '/collections', $headers, [
            'collectionId' => ID::unique(),
            'name' => 'Scopes',
            'permissions' => [],
            'documentSecurity' => false,
        ]);
        $this->assertSame(201, $collection['headers']['status-code']);

        $attribute = $this->client->call(Client::METHOD_POST, '/databases/' . $database['body']['$id'] . '/collections/' . $collection['body']['$id'] . '/attributes/string', $headers, [
            'key' => 'title',
            'size' => 128,
            'required' => false,
        ]);
        $this->assertSame(202, $attribute['headers']['status-code']);

        return [$database['body']['$id'], $collection['body']['$id']];
    }

    private function getCurrentScopedHeaders(): array
    {
        $key = $this->client->call(Client::METHOD_POST, '/project/keys', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'keyId' => ID::unique(),
            'name' => 'Current Databases scopes',
            'scopes' => self::CURRENT_SCOPES,
        ]);

        $this->assertSame(201, $key['headers']['status-code']);

        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $key['body']['secret'],
        ];
    }
}
