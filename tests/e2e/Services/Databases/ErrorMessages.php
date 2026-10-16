<?php

namespace Tests\E2E\Services\Databases;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;

trait ErrorMessages
{
    public function testIncrementOfAMissingRecordNamesTheRecord(): void
    {
        [$databaseId, $collectionId] = $this->createErrorMessagesCollection('incrementMissingRecord');

        foreach (['increment', 'decrement'] as $direction) {
            $response = $this->client->call(Client::METHOD_PATCH, $this->getRecordUrl($databaseId, $collectionId, 'missing') . '/count/' . $direction, $this->getErrorMessagesHeaders(), [
                'value' => 1,
            ]);

            $this->assertSame(404, $response['headers']['status-code'], $direction);
            $this->assertSame($this->isTablesAPI() ? Exception::ROW_NOT_FOUND : Exception::DOCUMENT_NOT_FOUND, $response['body']['type'], $direction);
            $this->assertSame($this->getRecordNoun() . " with the requested ID 'missing' could not be found.", $response['body']['message'], $direction);
        }
    }

    public function testIncrementOfARowTheCallerCannotReadLooksLikeAMissingRow(): void
    {
        if ($this->getSide() !== 'client') {
            $this->markTestSkipped('Needs a caller bound by row permissions');
        }

        $headers = $this->getErrorMessagesHeaders();
        [$databaseId, $collectionId] = $this->createErrorMessagesCollection('incrementUnreadable', [Permission::create(Role::any())], true);
        $hiddenId = $this->createErrorMessagesRecord($databaseId, $collectionId, ['count' => 1], []);
        $callerHeaders = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $fields = $this->getSupportForAttributes() ? ['nope', 'count'] : ['count'];
        foreach ($fields as $field) {
            foreach (['increment', 'decrement'] as $direction) {
                $responses = [];
                foreach (['missing' => Id::unique(), 'unreadable' => $hiddenId] as $case => $recordId) {
                    $response = $this->client->call(Client::METHOD_PATCH, $this->getRecordUrl($databaseId, $collectionId, $recordId) . '/' . $field . '/' . $direction, $callerHeaders, [
                        'value' => 1,
                    ]);
                    $responses[$case] = [
                        $response['headers']['status-code'],
                        $response['body']['type'] ?? null,
                        \str_replace($recordId, '{id}', $response['body']['message'] ?? ''),
                    ];
                }

                $this->assertSame([404, $this->isTablesAPI() ? Exception::ROW_NOT_FOUND : Exception::DOCUMENT_NOT_FOUND, $this->getRecordNoun() . " with the requested ID '{id}' could not be found."], $responses['missing'], "{$direction} {$field}");
                $this->assertSame($responses['missing'], $responses['unreadable'], "{$direction} {$field}: a row the caller cannot read answers as a missing row");
            }
        }

        $stored = $this->client->call(Client::METHOD_GET, $this->getRecordUrl($databaseId, $collectionId, $hiddenId), $headers);
        $this->assertSame(1, $stored['body']['count'], 'the hidden row is left unchanged');
    }

    public function testIncrementOfAMissingFieldNamesTheField(): void
    {
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Attributes are not supported by this database adapter');
        }

        [$databaseId, $collectionId] = $this->createErrorMessagesCollection('incrementMissingField');
        $recordId = $this->createErrorMessagesRecord($databaseId, $collectionId, ['count' => 1]);

        foreach (['increment', 'decrement'] as $direction) {
            $response = $this->client->call(Client::METHOD_PATCH, $this->getRecordUrl($databaseId, $collectionId, $recordId) . '/nope/' . $direction, $this->getErrorMessagesHeaders(), [
                'value' => 1,
            ]);

            $this->assertSame(404, $response['headers']['status-code'], $direction);
            $this->assertSame($this->isTablesAPI() ? Exception::COLUMN_NOT_FOUND : Exception::ATTRIBUTE_NOT_FOUND, $response['body']['type'], $direction);
            $this->assertSame(($this->isTablesAPI() ? 'Column' : 'Attribute') . " with the requested key 'nope' could not be found.", $response['body']['message'], $direction);
        }
    }

    public function testIncrementPastItsBoundNamesTheField(): void
    {
        [$databaseId, $collectionId] = $this->createErrorMessagesCollection('incrementPastBound');
        $url = $this->getRecordUrl($databaseId, $collectionId, $this->createErrorMessagesRecord($databaseId, $collectionId, ['count' => 5]));
        $field = $this->isTablesAPI() ? 'Column' : 'Attribute';

        $increased = $this->client->call(Client::METHOD_PATCH, $url . '/count/increment', $this->getErrorMessagesHeaders(), [
            'value' => 1,
            'max' => 5,
        ]);
        $this->assertSame(400, $increased['headers']['status-code']);
        $this->assertSame($field . ' "count" has reached the maximum value of 5', $increased['body']['message']);

        $decreased = $this->client->call(Client::METHOD_PATCH, $url . '/count/decrement', $this->getErrorMessagesHeaders(), [
            'value' => 6,
            'min' => 0,
        ]);
        $this->assertSame(400, $decreased['headers']['status-code']);
        $this->assertSame($field . ' "count" has reached the minimum value of 0', $decreased['body']['message']);
    }

    public function testCommittedIncrementOfANonNumericFieldNamesTheField(): void
    {
        if (!$this->getSupportForAttributes()) {
            $this->markTestSkipped('Attributes are not supported by this database adapter');
        }

        [$databaseId, $collectionId] = $this->createErrorMessagesCollection('committedIncrementNonNumeric');
        $headers = $this->getErrorMessagesHeaders();

        $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $collectionId) . '/string', $headers, [
            'key' => 'label',
            'size' => 32,
            'required' => false,
        ]);
        $this->assertSame(202, $attribute['headers']['status-code'], $attribute['body']['message'] ?? '');
        $this->waitForAttribute($databaseId, $collectionId, 'label');
        $recordId = $this->createErrorMessagesRecord($databaseId, $collectionId, ['count' => 1, 'label' => 'one']);

        $transaction = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl(), $headers);
        $this->assertSame(201, $transaction['headers']['status-code']);
        $transactionId = $transaction['body']['$id'];

        $staged = $this->client->call(Client::METHOD_POST, $this->getTransactionUrl($transactionId) . '/operations', $headers, [
            'operations' => [[
                'databaseId' => $databaseId,
                $this->getContainerIdParam() => $collectionId,
                'action' => 'increment',
                $this->getRecordIdParam() => $recordId,
                'data' => [($this->isTablesAPI() ? 'column' : 'attribute') => 'label', 'value' => 1],
            ]],
        ]);
        $this->assertSame(201, $staged['headers']['status-code'], \json_encode($staged['body']) ?: '');

        $commit = $this->client->call(Client::METHOD_PATCH, $this->getTransactionUrl($transactionId), $headers, ['commit' => true]);
        $this->assertSame(400, $commit['headers']['status-code'], \json_encode($commit['body']) ?: '');
        $this->assertSame(($this->isTablesAPI() ? 'Column' : 'Attribute') . ' "label" is not a number', $commit['body']['message']);
    }

    public function testBulkCreateOfAnExistingIdNamesTheId(): void
    {
        [$databaseId, $collectionId] = $this->createErrorMessagesCollection('bulkCreateExisting');
        $existingId = $this->createErrorMessagesRecord($databaseId, $collectionId, ['count' => 1]);

        $response = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $collectionId), $this->getErrorMessagesHeaders(), [
            $this->getRecordResource() => [
                ['$id' => Id::unique(), 'count' => 2],
                ['$id' => $existingId, 'count' => 3],
            ],
        ]);

        $this->assertSame(409, $response['headers']['status-code'], \json_encode($response['body']) ?: '');
        $this->assertSame($this->isTablesAPI() ? Exception::ROW_ALREADY_EXISTS : Exception::DOCUMENT_ALREADY_EXISTS, $response['body']['type']);
        $this->assertStringStartsWith($this->getRecordNoun() . " with the requested ID '{$existingId}' already exists.", $response['body']['message']);
    }

    public function testBulkCreateOfARepeatedIdNamesTheId(): void
    {
        $this->assertBulkWriteOfARepeatedIdNamesTheId(Client::METHOD_POST, 'bulkCreateRepeated');
    }

    public function testBulkUpsertOfARepeatedIdNamesTheId(): void
    {
        $this->assertBulkWriteOfARepeatedIdNamesTheId(Client::METHOD_PUT, 'bulkUpsertRepeated');
    }

    private function assertBulkWriteOfARepeatedIdNamesTheId(string $method, string $name): void
    {
        [$databaseId, $collectionId] = $this->createErrorMessagesCollection($name);
        $repeatedId = Id::unique();

        $response = $this->client->call($method, $this->getRecordUrl($databaseId, $collectionId), $this->getErrorMessagesHeaders(), [
            $this->getRecordResource() => [
                ['$id' => $repeatedId, 'count' => 1],
                ['$id' => $repeatedId, 'count' => 2],
            ],
        ]);

        $this->assertSame(409, $response['headers']['status-code'], \json_encode($response['body']) ?: '');
        $this->assertSame($this->isTablesAPI() ? Exception::ROW_ALREADY_EXISTS : Exception::DOCUMENT_ALREADY_EXISTS, $response['body']['type']);
        $this->assertStringStartsWith($this->getRecordNoun() . " with the requested ID '{$repeatedId}' already exists.", $response['body']['message']);
    }

    /**
     * @param list<string>|null $permissions
     * @return array{string, string}
     */
    private function createErrorMessagesCollection(string $name, ?array $permissions = null, bool $documentSecurity = false): array
    {
        $headers = $this->getErrorMessagesHeaders();

        $database = $this->client->call(Client::METHOD_POST, $this->getDatabaseUrl(), $headers, [
            'databaseId' => Id::unique(),
            'name' => $name,
        ]);
        $this->assertSame(201, $database['headers']['status-code'], $database['body']['message'] ?? '');

        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($database['body']['$id']), $headers, [
            $this->getContainerIdParam() => Id::unique(),
            'name' => $name,
            'permissions' => $permissions ?? [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
            ],
            $this->getSecurityParam() => $documentSecurity,
        ]);
        $this->assertSame(201, $collection['headers']['status-code'], $collection['body']['message'] ?? '');

        if ($this->getSupportForAttributes()) {
            $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($database['body']['$id'], $collection['body']['$id']) . '/integer', $headers, [
                'key' => 'count',
                'required' => true,
            ]);
            $this->assertSame(202, $attribute['headers']['status-code'], $attribute['body']['message'] ?? '');
            $this->waitForAttribute($database['body']['$id'], $collection['body']['$id'], 'count');
        }

        return [$database['body']['$id'], $collection['body']['$id']];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>|null $permissions
     */
    private function createErrorMessagesRecord(string $databaseId, string $collectionId, array $data, ?array $permissions = null): string
    {
        $record = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $collectionId), $this->getErrorMessagesHeaders(), \array_filter([
            $this->getRecordIdParam() => Id::unique(),
            'data' => $data,
            'permissions' => $permissions,
        ], static fn (mixed $value): bool => $value !== null));
        $this->assertSame(201, $record['headers']['status-code'], $record['body']['message'] ?? '');

        return $record['body']['$id'];
    }

    private function isTablesAPI(): bool
    {
        return $this->getRecordResource() === 'rows';
    }

    private function getRecordNoun(): string
    {
        return $this->isTablesAPI() ? 'Row' : 'Document';
    }

    /**
     * @return array<string, string>
     */
    private function getErrorMessagesHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }
}
