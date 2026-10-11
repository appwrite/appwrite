<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Transactions;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

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
}
