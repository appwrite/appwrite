<?php

namespace Tests\E2E\Services\Databases\Transactions;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Utopia\Database\Id;

trait StagedDependants
{
    public function testDeleteOfARecordAnEarlierOperationInTheSameBatchWrites(): void
    {
        $databaseId = $this->getSharedDatabase();
        $collectionId = $this->getSharedCollection();
        $headers = $this->getStagedDependantsKeyHeaders();

        $records = [
            'bulkCreate' => Id::unique(),
            'bulkUpsert' => Id::unique(),
            'upsert' => Id::unique(),
            'create' => Id::unique(),
        ];

        $operations = [];
        foreach ($records as $action => $recordId) {
            $operations[] = $this->writeOperation($databaseId, $collectionId, $action, $recordId);
            $operations[] = [
                'databaseId' => $databaseId,
                $this->getContainerIdParam() => $collectionId,
                'action' => 'delete',
                $this->getRecordIdParam() => $recordId,
            ];
        }

        $transactionId = $this->createStagedDependantsTransaction();
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId) . '/operations', $headers, [
            'operations' => $operations,
        ]);
        $this->assertSame(201, $staged['headers']['status-code'], \json_encode($staged['body']) ?: '');
        $this->assertSame(\count($operations), $staged['body']['operations']);

        $commit = $this->client->call(Client::METHOD_PATCH, $this->getTransactionUrl($transactionId), $headers, ['commit' => true]);
        $this->assertSame(200, $commit['headers']['status-code'], \json_encode($commit['body']) ?: '');

        foreach ($records as $action => $recordId) {
            $record = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $collectionId, $recordId), $headers);
            $this->assertSame(404, $record['headers']['status-code'], "A record the batch writes with {$action} and then deletes must not exist after commit");
        }
    }

    public function testOperationOnARecordAnEarlierOperationInTheSameBatchDeletesIsNotFound(): void
    {
        $databaseId = $this->getSharedDatabase();
        $collectionId = $this->getSharedCollection();
        $recordId = Id::unique();

        $transactionId = $this->createStagedDependantsTransaction();
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId) . '/operations', $this->getStagedDependantsKeyHeaders(), [
            'operations' => [
                $this->writeOperation($databaseId, $collectionId, 'create', $recordId),
                [
                    'databaseId' => $databaseId,
                    $this->getContainerIdParam() => $collectionId,
                    'action' => 'delete',
                    $this->getRecordIdParam() => $recordId,
                ],
                [
                    'databaseId' => $databaseId,
                    $this->getContainerIdParam() => $collectionId,
                    'action' => 'update',
                    $this->getRecordIdParam() => $recordId,
                    'data' => ['name' => 'after delete'],
                ],
            ],
        ]);

        $tables = $this->getRecordResource() === 'rows';
        $this->assertSame(404, $staged['headers']['status-code'], \json_encode($staged['body']) ?: '');
        $this->assertSame($tables ? Exception::ROW_NOT_FOUND : Exception::DOCUMENT_NOT_FOUND, $staged['body']['type']);
        $this->assertSame(($tables ? 'Row' : 'Document') . " with the requested ID '{$recordId}' could not be found.", $staged['body']['message']);
    }

    public function testUpsertOperationCommitsUnderItsRecordId(): void
    {
        $databaseId = $this->getSharedDatabase();
        $collectionId = $this->getSharedCollection();
        $headers = $this->getStagedDependantsKeyHeaders();

        $existingId = Id::unique();
        $existing = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $collectionId), $headers, [
            $this->getRecordIdParam() => $existingId,
            'data' => ['name' => 'before'],
        ]);
        $this->assertSame(201, $existing['headers']['status-code'], \json_encode($existing['body']) ?: '');

        $newId = Id::unique();
        $targetId = Id::unique();
        $strayId = Id::unique();
        $transactionId = $this->createStagedDependantsTransaction();
        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId) . '/operations', $headers, [
            'operations' => [
                $this->writeOperation($databaseId, $collectionId, 'upsert', $newId),
                [
                    'databaseId' => $databaseId,
                    $this->getContainerIdParam() => $collectionId,
                    'action' => 'upsert',
                    $this->getRecordIdParam() => $existingId,
                    'data' => ['name' => 'after'],
                ],
                [
                    'databaseId' => $databaseId,
                    $this->getContainerIdParam() => $collectionId,
                    'action' => 'upsert',
                    $this->getRecordIdParam() => $targetId,
                    'data' => ['$id' => $strayId, 'name' => 'target'],
                ],
            ],
        ]);
        $this->assertSame(201, $staged['headers']['status-code'], \json_encode($staged['body']) ?: '');

        $commit = $this->client->call(Client::METHOD_PATCH, $this->getTransactionUrl($transactionId), $headers, ['commit' => true]);
        $this->assertSame(200, $commit['headers']['status-code'], \json_encode($commit['body']) ?: '');

        $created = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $collectionId, $newId), $headers);
        $this->assertSame(200, $created['headers']['status-code'], 'An upsert of a new record commits under the record ID it was staged with');
        $this->assertSame('upsert', $created['body']['name']);

        $updated = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $collectionId, $existingId), $headers);
        $this->assertSame(200, $updated['headers']['status-code']);
        $this->assertSame('after', $updated['body']['name'], 'An upsert of an existing record updates that record');

        $target = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $collectionId, $targetId), $headers);
        $this->assertSame(200, $target['headers']['status-code'], 'The record ID of the operation wins over an $id in its data');
        $this->assertSame('target', $target['body']['name']);

        $stray = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $collectionId, $strayId), $headers);
        $this->assertSame(404, $stray['headers']['status-code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function writeOperation(string $databaseId, string $collectionId, string $action, string $recordId): array
    {
        $operation = [
            'databaseId' => $databaseId,
            $this->getContainerIdParam() => $collectionId,
            'action' => $action,
        ];

        if (\in_array($action, ['bulkCreate', 'bulkUpsert'], true)) {
            $operation['data'] = [['$id' => $recordId, 'name' => $action]];

            return $operation;
        }

        $operation[$this->getRecordIdParam()] = $recordId;
        $operation['data'] = ['name' => $action];

        return $operation;
    }

    private function createStagedDependantsTransaction(): string
    {
        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), $this->getStagedDependantsKeyHeaders());
        $this->assertSame(201, $transaction['headers']['status-code'], \json_encode($transaction['body']) ?: '');

        return $transaction['body']['$id'];
    }

    /**
     * @return array<string, string>
     */
    private function getStagedDependantsKeyHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }
}
