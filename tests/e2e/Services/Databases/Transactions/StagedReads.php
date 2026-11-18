<?php

namespace Tests\E2E\Services\Databases\Transactions;

use Tests\E2E\Client;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;

trait StagedReads
{
    public function testListInATransactionFiltersStagedWrites(): void
    {
        [$databaseId, $collectionId, $transactionId, $ids] = $this->stageReadsFixture();

        $bob = $this->listStagedReads($databaseId, $collectionId, $transactionId, [Query::equal('name', ['Bob'])]);
        $this->assertSame([$ids['bob']], \array_column($bob['body'][$this->getRecordResource()], '$id'), 'a staged create that misses the filter is left out');
        $this->assertSame(1, $bob['body']['total']);

        $ada = $this->listStagedReads($databaseId, $collectionId, $transactionId, [Query::equal('name', ['Ada'])]);
        $this->assertSame([], $ada['body'][$this->getRecordResource()], 'a row whose staged update no longer matches is left out');
        $this->assertSame(0, $ada['body']['total']);

        $zed = $this->listStagedReads($databaseId, $collectionId, $transactionId, [Query::equal('name', ['Zed'])]);
        $this->assertSame([$ids['ada']], \array_column($zed['body'][$this->getRecordResource()], '$id'), 'a row matches on its staged value');
        $this->assertSame(1, $zed['body']['total']);
    }

    public function testListInATransactionOrdersAndPagesStagedWrites(): void
    {
        [$databaseId, $collectionId, $transactionId, $ids] = $this->stageReadsFixture();

        $descending = $this->listStagedReads($databaseId, $collectionId, $transactionId, [Query::orderDesc('name'), Query::limit(2)]);
        $this->assertSame([$ids['ada'], $ids['cy']], \array_column($descending['body'][$this->getRecordResource()], '$id'), 'orderDesc and limit apply to the merged rows');
        $this->assertSame(4, $descending['body']['total'], 'total counts every visible row, not the page');

        $offset = $this->listStagedReads($databaseId, $collectionId, $transactionId, [Query::orderAsc('name'), Query::offset(1), Query::limit(2)]);
        $this->assertSame([$ids['bob'], $ids['cy']], \array_column($offset['body'][$this->getRecordResource()], '$id'));

        $afterStored = $this->listStagedReads($databaseId, $collectionId, $transactionId, [Query::orderAsc('name'), Query::cursorAfter(new Document(['$id' => $ids['bob']]))]);
        $this->assertSame([$ids['cy'], $ids['ada']], \array_column($afterStored['body'][$this->getRecordResource()], '$id'), 'a cursor on a stored row skips the staged rows before it');

        $afterStaged = $this->listStagedReads($databaseId, $collectionId, $transactionId, [Query::orderAsc('name'), Query::cursorAfter(new Document(['$id' => $ids['abe']]))]);
        $this->assertSame(200, $afterStaged['headers']['status-code'], \json_encode($afterStaged['body']) ?: '');
        $this->assertSame([$ids['bob'], $ids['cy'], $ids['ada']], \array_column($afterStaged['body'][$this->getRecordResource()], '$id'), 'a cursor may be a staged row');
    }

    /**
     * Stored rows Ada and Bob; staged in the transaction: Cy and Abe created, Ada renamed to Zed.
     *
     * @return array{string, string, string, array<string, string>}
     */
    private function stageReadsFixture(): array
    {
        $headers = $this->getStagedReadsHeaders();

        $database = $this->client->call(Client::METHOD_POST, $this->getDatabaseUrl(), $headers, [
            'databaseId' => Id::unique(),
            'name' => 'StagedReads',
        ]);
        $this->assertSame(201, $database['headers']['status-code'], $database['body']['message'] ?? '');
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $headers, [
            $this->getContainerIdParam() => Id::unique(),
            'name' => 'StagedReads',
            'permissions' => [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
        ]);
        $this->assertSame(201, $collection['headers']['status-code'], $collection['body']['message'] ?? '');
        $collectionId = $collection['body']['$id'];

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $collectionId, 'string', null), $headers, [
                'key' => 'name',
                'size' => 64,
                'required' => true,
            ]);
            $this->assertSame(202, $attribute['headers']['status-code'], $attribute['body']['message'] ?? '');
            $this->waitForAllAttributes($databaseId, $collectionId);
        }

        $ids = [];
        foreach (['ada' => 'Ada', 'bob' => 'Bob'] as $key => $name) {
            $record = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $collectionId), $headers, [
                $this->getRecordIdParam() => Id::unique(),
                'data' => ['name' => $name],
            ]);
            $this->assertSame(201, $record['headers']['status-code'], $record['body']['message'] ?? '');
            $ids[$key] = $record['body']['$id'];
        }
        $ids['cy'] = Id::unique();
        $ids['abe'] = Id::unique();

        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), $headers);
        $this->assertSame(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        $operation = fn (string $action, string $recordId, string $name): array => [
            'databaseId' => $databaseId,
            $this->getContainerIdParam() => $collectionId,
            'action' => $action,
            $this->getRecordIdParam() => $recordId,
            'data' => ['name' => $name],
        ];
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId) . '/operations', $headers, [
            'operations' => [
                $operation('create', $ids['cy'], 'Cy'),
                $operation('create', $ids['abe'], 'Abe'),
                $operation('update', $ids['ada'], 'Zed'),
            ],
        ]);
        $this->assertSame(201, $staged['headers']['status-code'], \json_encode($staged['body']) ?: '');

        return [$databaseId, $collectionId, $transactionId, $ids];
    }

    /**
     * @param list<Query> $queries
     * @return array<string, mixed>
     */
    private function listStagedReads(string $databaseId, string $collectionId, string $transactionId, array $queries): array
    {
        return $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $collectionId), $this->getStagedReadsHeaders(), [
            'queries' => \array_map(static fn (Query $query): string => $query->toString(), $queries),
            'transactionId' => $transactionId,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function getStagedReadsHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }
}
