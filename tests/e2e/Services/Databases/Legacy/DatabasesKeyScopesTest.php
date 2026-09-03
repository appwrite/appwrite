<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

/**
 * The legacy /v1/databases surface is gated on collections.* / attributes.* / documents.*,
 * all flagged deprecated in app/config/scopes/project.php and hidden by the console key
 * editor. A key created today therefore carries only the current Databases scopes, so the
 * legacy routes must accept those alongside the deprecated names they were named after,
 * the same way the /v1/tablesdb routes accept both.
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
    ];

    /**
     * The deprecated names the legacy routes were originally gated on. Keys minted before
     * the rename still carry only these, so they have to keep working.
     */
    private const DEPRECATED_SCOPES = [
        'databases.read',
        'databases.write',
        'collections.read',
        'collections.write',
        'attributes.read',
        'attributes.write',
        'documents.read',
        'documents.write',
    ];

    public static function scopesProvider(): \Iterator
    {
        yield 'current scopes' => [self::CURRENT_SCOPES];
        yield 'deprecated scopes' => [self::DEPRECATED_SCOPES];
    }

    #[DataProvider('scopesProvider')]
    public function testUpdateCollection(array $scopes): void
    {
        [$databaseId, $collectionId] = $this->createCollection();

        $response = $this->client->call(
            Client::METHOD_PUT,
            '/databases/' . $databaseId . '/collections/' . $collectionId,
            $this->getScopedHeaders($scopes),
            [
                'name' => 'Renamed',
                'permissions' => [],
            ]
        );

        $this->assertSame(200, $response['headers']['status-code'], $response['body']['message'] ?? '');
        $this->assertSame('Renamed', $response['body']['name']);
    }

    #[DataProvider('scopesProvider')]
    public function testListCollections(array $scopes): void
    {
        [$databaseId] = $this->createCollection();

        $response = $this->client->call(
            Client::METHOD_GET,
            '/databases/' . $databaseId . '/collections',
            $this->getScopedHeaders($scopes)
        );

        $this->assertSame(200, $response['headers']['status-code'], $response['body']['message'] ?? '');
    }

    #[DataProvider('scopesProvider')]
    public function testCreateAttribute(array $scopes): void
    {
        [$databaseId, $collectionId] = $this->createCollection();

        $response = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string',
            $this->getScopedHeaders($scopes),
            [
                'key' => 'subtitle',
                'size' => 128,
                'required' => false,
            ]
        );

        $this->assertSame(202, $response['headers']['status-code'], $response['body']['message'] ?? '');
    }

    #[DataProvider('scopesProvider')]
    public function testCreateIndex(array $scopes): void
    {
        [$databaseId, $collectionId] = $this->createCollection();

        $response = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/indexes',
            $this->getScopedHeaders($scopes),
            [
                'key' => 'title_index',
                'type' => 'key',
                'attributes' => ['title'],
            ]
        );

        $this->assertSame(202, $response['headers']['status-code'], $response['body']['message'] ?? '');
    }

    #[DataProvider('scopesProvider')]
    public function testCreateDocument(array $scopes): void
    {
        [$databaseId, $collectionId] = $this->createCollection();
        $headers = $this->getScopedHeaders($scopes);

        $response = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
            $headers,
            [
                'documentId' => ID::unique(),
                'data' => ['title' => 'Hello'],
            ]
        );

        $this->assertSame(201, $response['headers']['status-code'], $response['body']['message'] ?? '');

        $list = $this->client->call(
            Client::METHOD_GET,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
            $headers
        );

        $this->assertSame(200, $list['headers']['status-code'], $list['body']['message'] ?? '');
    }

    #[DataProvider('scopesProvider')]
    public function testCreateTransaction(array $scopes): void
    {
        $response = $this->client->call(
            Client::METHOD_POST,
            '/databases/transactions',
            $this->getScopedHeaders($scopes)
        );

        $this->assertSame(201, $response['headers']['status-code'], $response['body']['message'] ?? '');
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

    /**
     * @param array<string> $scopes
     */
    private function getScopedHeaders(array $scopes): array
    {
        $key = $this->client->call(Client::METHOD_POST, '/project/keys', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], [
            'keyId' => ID::unique(),
            'name' => 'Databases scopes',
            'scopes' => $scopes,
        ]);

        $this->assertSame(201, $key['headers']['status-code']);

        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $key['body']['secret'],
        ];
    }
}
