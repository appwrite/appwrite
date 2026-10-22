<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Transactions;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;

final class TransactionsFamilyCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    /**
     * @return array<string, string>
     */
    private function families(): array
    {
        return [
            'legacy' => '/databases/transactions',
            'tablesdb' => '/tablesdb/transactions',
            'documentsdb' => '/documentsdb/transactions',
            'vectorsdb' => '/vectorsdb/transactions',
        ];
    }

    /**
     * Legacy Databases and TablesDB are the same product either side of a
     * rename, so each path serves both. DocumentsDB and VectorsDB stay on
     * the family that created the transaction.
     *
     * @return list<string>
     */
    private function visibleOn(string $family): array
    {
        return match ($family) {
            'legacy', 'tablesdb' => ['legacy', 'tablesdb'],
            default => [$family],
        };
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    /**
     * @return array<string, mixed>
     */
    private function operationPayload(string $family): array
    {
        $containerKey = $family === 'tablesdb' ? 'tableId' : 'collectionId';
        $recordKey = $family === 'tablesdb' ? 'rowId' : 'documentId';

        return [
            'action' => 'create',
            'databaseId' => 'familyDb',
            $containerKey => 'familyContainer',
            $recordKey => 'familyRecord',
            'data' => ['name' => 'family'],
        ];
    }

    public function testListAndGetStayOnCreatingFamily(): void
    {
        $headers = $this->requestHeaders();
        $created = [];

        foreach ($this->families() as $family => $path) {
            $response = $this->client->call(Client::METHOD_POST, $path, $headers);
            $this->assertSame(201, $response['headers']['status-code'], $family);
            $created[$family] = $response['body']['$id'];
        }

        foreach ($this->families() as $family => $path) {
            $list = $this->client->call(Client::METHOD_GET, $path, $headers);
            $this->assertSame(200, $list['headers']['status-code'], $family);

            $ids = \array_column($list['body']['transactions'], '$id');
            $visible = $this->visibleOn($family);

            foreach ($created as $source => $id) {
                if (\in_array($source, $visible, true)) {
                    $this->assertContains($id, $ids, "{$source} should be listed on {$family}");
                } else {
                    $this->assertNotContains($id, $ids, "{$source} should not be listed on {$family}");
                }
            }
        }

        foreach ($this->families() as $family => $path) {
            foreach ($created as $source => $id) {
                $get = $this->client->call(Client::METHOD_GET, $path . '/' . $id, $headers);
                $visible = \in_array($source, $this->visibleOn($family), true);

                if ($visible) {
                    $this->assertSame(200, $get['headers']['status-code'], "get {$source} on {$family}");
                    $this->assertSame($id, $get['body']['$id']);
                    $this->assertSame('pending', $get['body']['status']);
                } else {
                    $this->assertSame(404, $get['headers']['status-code'], "get {$source} on {$family}");
                    $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $get['body']['type']);
                }
            }
        }
    }

    public function testUpdateDeleteAndOperationsStayOnCreatingFamily(): void
    {
        $headers = $this->requestHeaders();
        $families = $this->families();

        $vectorsdb = $this->client->call(Client::METHOD_POST, $families['vectorsdb'], $headers);
        $this->assertSame(201, $vectorsdb['headers']['status-code']);
        $vectorsdbId = $vectorsdb['body']['$id'];

        $commitOnTables = $this->client->call(Client::METHOD_PATCH, $families['tablesdb'] . '/' . $vectorsdbId, $headers, [
            'commit' => true,
        ]);
        $this->assertSame(404, $commitOnTables['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $commitOnTables['body']['type']);

        $stillPending = $this->client->call(Client::METHOD_GET, $families['vectorsdb'] . '/' . $vectorsdbId, $headers);
        $this->assertSame(200, $stillPending['headers']['status-code']);
        $this->assertSame('pending', $stillPending['body']['status']);

        $rollbackOnFamily = $this->client->call(Client::METHOD_PATCH, $families['vectorsdb'] . '/' . $vectorsdbId, $headers, [
            'rollback' => true,
        ]);
        $this->assertSame(200, $rollbackOnFamily['headers']['status-code']);
        $this->assertSame('failed', $rollbackOnFamily['body']['status']);

        $tablesdb = $this->client->call(Client::METHOD_POST, $families['tablesdb'], $headers);
        $this->assertSame(201, $tablesdb['headers']['status-code']);
        $tablesdbId = $tablesdb['body']['$id'];

        $commitOnVectors = $this->client->call(Client::METHOD_PATCH, $families['vectorsdb'] . '/' . $tablesdbId, $headers, [
            'commit' => true,
        ]);
        $this->assertSame(404, $commitOnVectors['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $commitOnVectors['body']['type']);

        $commitOnLegacy = $this->client->call(Client::METHOD_PATCH, $families['legacy'] . '/' . $tablesdbId, $headers, [
            'commit' => true,
        ]);
        $this->assertSame(200, $commitOnLegacy['headers']['status-code']);
        $this->assertSame('committed', $commitOnLegacy['body']['status']);

        $documentsdb = $this->client->call(Client::METHOD_POST, $families['documentsdb'], $headers);
        $this->assertSame(201, $documentsdb['headers']['status-code']);
        $documentsdbId = $documentsdb['body']['$id'];

        $deleteOnTables = $this->client->call(Client::METHOD_DELETE, $families['tablesdb'] . '/' . $documentsdbId, $headers);
        $this->assertSame(404, $deleteOnTables['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $deleteOnTables['body']['type']);

        $stillThere = $this->client->call(Client::METHOD_GET, $families['documentsdb'] . '/' . $documentsdbId, $headers);
        $this->assertSame(200, $stillThere['headers']['status-code']);

        $deleteOnFamily = $this->client->call(Client::METHOD_DELETE, $families['documentsdb'] . '/' . $documentsdbId, $headers);
        $this->assertSame(204, $deleteOnFamily['headers']['status-code']);

        $gone = $this->client->call(Client::METHOD_GET, $families['documentsdb'] . '/' . $documentsdbId, $headers);
        $this->assertSame(404, $gone['headers']['status-code']);

        $legacy = $this->client->call(Client::METHOD_POST, $families['legacy'], $headers);
        $this->assertSame(201, $legacy['headers']['status-code']);
        $legacyId = $legacy['body']['$id'];

        $operationsOnVectors = $this->client->call(Client::METHOD_POST, $families['vectorsdb'] . '/' . $legacyId . '/operations', $headers, [
            'operations' => [$this->operationPayload('vectorsdb')],
        ]);
        $this->assertSame(404, $operationsOnVectors['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $operationsOnVectors['body']['type']);

        $operationsOnDocuments = $this->client->call(Client::METHOD_POST, $families['documentsdb'] . '/' . $legacyId . '/operations', $headers, [
            'operations' => [$this->operationPayload('documentsdb')],
        ]);
        $this->assertSame(404, $operationsOnDocuments['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $operationsOnDocuments['body']['type']);

        $getOnLegacy = $this->client->call(Client::METHOD_GET, $families['legacy'] . '/' . $legacyId, $headers);
        $this->assertSame(200, $getOnLegacy['headers']['status-code']);
        $this->assertSame(0, $getOnLegacy['body']['operations']);
    }

    public function testStagedDocumentAndRowWritesStayOnCreatingFamily(): void
    {
        $headers = $this->requestHeaders();
        $families = $this->families();

        $vectorsdb = $this->client->call(Client::METHOD_POST, $families['vectorsdb'], $headers);
        $this->assertSame(201, $vectorsdb['headers']['status-code']);
        $mismatchedId = $vectorsdb['body']['$id'];

        [$databaseId, $collectionId, $documentId] = $this->createLegacyCollection();
        [$tableDatabaseId, $tableId, $rowId] = $this->createTablesCollection();

        $documentCreate = $this->client->call(
            Client::METHOD_POST,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents',
            $headers,
            [
                'documentId' => Id::unique(),
                'data' => ['name' => 'mismatch', 'counter' => 1],
                'transactionId' => $mismatchedId,
            ]
        );
        $this->assertSame(404, $documentCreate['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $documentCreate['body']['type']);

        $documentUpdate = $this->client->call(
            Client::METHOD_PATCH,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId,
            $headers,
            [
                'data' => ['name' => 'mismatch-update'],
                'transactionId' => $mismatchedId,
            ]
        );
        $this->assertSame(404, $documentUpdate['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $documentUpdate['body']['type']);

        $documentDelete = $this->client->call(
            Client::METHOD_DELETE,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId,
            $headers,
            ['transactionId' => $mismatchedId]
        );
        $this->assertSame(404, $documentDelete['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $documentDelete['body']['type']);

        $documentIncrement = $this->client->call(
            Client::METHOD_PATCH,
            '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $documentId . '/counter/increment',
            $headers,
            [
                'value' => 1,
                'transactionId' => $mismatchedId,
            ]
        );
        $this->assertSame(404, $documentIncrement['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $documentIncrement['body']['type']);

        $rowCreate = $this->client->call(
            Client::METHOD_POST,
            '/tablesdb/' . $tableDatabaseId . '/tables/' . $tableId . '/rows',
            $headers,
            [
                'rowId' => Id::unique(),
                'data' => ['name' => 'mismatch', 'counter' => 1],
                'transactionId' => $mismatchedId,
            ]
        );
        $this->assertSame(404, $rowCreate['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $rowCreate['body']['type']);

        $rowUpdate = $this->client->call(
            Client::METHOD_PATCH,
            '/tablesdb/' . $tableDatabaseId . '/tables/' . $tableId . '/rows/' . $rowId,
            $headers,
            [
                'data' => ['name' => 'mismatch-update'],
                'transactionId' => $mismatchedId,
            ]
        );
        $this->assertSame(404, $rowUpdate['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $rowUpdate['body']['type']);

        $rowDelete = $this->client->call(
            Client::METHOD_DELETE,
            '/tablesdb/' . $tableDatabaseId . '/tables/' . $tableId . '/rows/' . $rowId,
            $headers,
            ['transactionId' => $mismatchedId]
        );
        $this->assertSame(404, $rowDelete['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $rowDelete['body']['type']);

        $rowIncrement = $this->client->call(
            Client::METHOD_PATCH,
            '/tablesdb/' . $tableDatabaseId . '/tables/' . $tableId . '/rows/' . $rowId . '/counter/increment',
            $headers,
            [
                'value' => 1,
                'transactionId' => $mismatchedId,
            ]
        );
        $this->assertSame(404, $rowIncrement['headers']['status-code']);
        $this->assertSame(Exception::TRANSACTION_NOT_FOUND, $rowIncrement['body']['type']);

        $stillPending = $this->client->call(Client::METHOD_GET, $families['vectorsdb'] . '/' . $mismatchedId, $headers);
        $this->assertSame(200, $stillPending['headers']['status-code']);
        $this->assertSame('pending', $stillPending['body']['status']);
        $this->assertSame(0, $stillPending['body']['operations']);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function createLegacyCollection(): array
    {
        return $this->createStagedSurface(
            databasePath: '/databases',
            containerPath: static fn (string $databaseId): string => '/databases/' . $databaseId . '/collections',
            schemaPath: static fn (string $databaseId, string $containerId, string $type): string => '/databases/' . $databaseId . '/collections/' . $containerId . '/attributes/' . $type,
            recordPath: static fn (string $databaseId, string $containerId): string => '/databases/' . $databaseId . '/collections/' . $containerId . '/documents',
            containerIdKey: 'collectionId',
            recordIdKey: 'documentId',
            schemaResource: 'attributes',
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function createTablesCollection(): array
    {
        return $this->createStagedSurface(
            databasePath: '/tablesdb',
            containerPath: static fn (string $databaseId): string => '/tablesdb/' . $databaseId . '/tables',
            schemaPath: static fn (string $databaseId, string $containerId, string $type): string => '/tablesdb/' . $databaseId . '/tables/' . $containerId . '/columns/' . $type,
            recordPath: static fn (string $databaseId, string $containerId): string => '/tablesdb/' . $databaseId . '/tables/' . $containerId . '/rows',
            containerIdKey: 'tableId',
            recordIdKey: 'rowId',
            schemaResource: 'columns',
        );
    }

    /**
     * @param callable(string): string $containerPath
     * @param callable(string, string, string): string $schemaPath
     * @param callable(string, string): string $recordPath
     * @return array{0: string, 1: string, 2: string}
     */
    private function createStagedSurface(
        string $databasePath,
        callable $containerPath,
        callable $schemaPath,
        callable $recordPath,
        string $containerIdKey,
        string $recordIdKey,
        string $schemaResource,
    ): array {
        $headers = $this->requestHeaders();

        $database = $this->client->call(Client::METHOD_POST, $databasePath, $headers, [
            'databaseId' => Id::unique(),
            'name' => 'FamilyStageDB',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $container = $this->client->call(Client::METHOD_POST, $containerPath($databaseId), $headers, [
            $containerIdKey => Id::unique(),
            'name' => 'FamilyStageContainer',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertSame(201, $container['headers']['status-code']);
        $containerId = $container['body']['$id'];

        $string = $this->client->call(Client::METHOD_POST, $schemaPath($databaseId, $containerId, 'string'), $headers, [
            'key' => 'name',
            'size' => 256,
            'required' => true,
        ]);
        $this->assertSame(202, $string['headers']['status-code']);

        $integer = $this->client->call(Client::METHOD_POST, $schemaPath($databaseId, $containerId, 'integer'), $headers, [
            'key' => 'counter',
            'required' => false,
            'min' => 0,
            'max' => 10000,
        ]);
        $this->assertSame(202, $integer['headers']['status-code']);

        $this->assertEventually(function () use ($containerPath, $databaseId, $containerId, $schemaResource, $headers) {
            $response = $this->client->call(Client::METHOD_GET, $containerPath($databaseId) . '/' . $containerId, $headers);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertNotEmpty($response['body'][$schemaResource] ?? []);

            foreach ($response['body'][$schemaResource] as $attribute) {
                $this->assertSame('available', $attribute['status'] ?? '');
            }
        });

        $record = $this->client->call(Client::METHOD_POST, $recordPath($databaseId, $containerId), $headers, [
            $recordIdKey => Id::unique(),
            'data' => [
                'name' => 'seed',
                'counter' => 1,
            ],
        ]);
        $this->assertSame(201, $record['headers']['status-code']);

        return [$databaseId, $containerId, $record['body']['$id']];
    }
}
