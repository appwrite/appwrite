<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiLegacy;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SchemaPolling;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Traits\DatabasesUrlHelpers;
use Utopia\Database\Id;

/**
 * A schema job the database refuses ends with the refusal on the resource.
 *
 * A unique index over rows that already repeat cannot be built. The worker
 * records that on the index as `failed` with the database's reason, and that
 * record is the whole result of the job: the customer reads it, and nothing
 * about the worker went wrong.
 */
final class SchemaRejectionCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use ApiLegacy;
    use DatabasesUrlHelpers;
    use SchemaPolling;

    public function testUniqueIndexOverDuplicateRowsEndsFailedWithTheReason(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];

        $database = $this->client->call(Client::METHOD_POST, $this->getApiBasePath(), $headers, [
            'databaseId' => Id::unique(),
            'name' => 'Schema Rejection DB',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, $this->getContainerUrl($databaseId), $headers, [
            $this->getContainerIdParam() => Id::unique(),
            'name' => 'Operators',
        ]);
        $this->assertSame(201, $collection['headers']['status-code']);
        $collectionId = $collection['body']['$id'];

        $attribute = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $collectionId, 'string'), $headers, [
            'key' => 'email',
            'size' => 128,
            'required' => false,
        ]);
        $this->assertSame(202, $attribute['headers']['status-code']);
        $this->waitForAttribute($databaseId, $collectionId, 'email');

        foreach ([Id::unique(), Id::unique()] as $documentId) {
            $document = $this->client->call(Client::METHOD_POST, $this->getRecordUrl($databaseId, $collectionId), $headers, [
                $this->getRecordIdParam() => $documentId,
                'data' => ['email' => 'same@example.com'],
            ]);
            $this->assertSame(201, $document['headers']['status-code']);
        }

        $index = $this->client->call(Client::METHOD_POST, $this->getIndexUrl($databaseId, $collectionId), $headers, [
            'key' => 'email_unique',
            'type' => 'unique',
            $this->getIndexAttributesParam() => ['email'],
        ]);
        $this->assertSame(202, $index['headers']['status-code']);

        $this->assertEventually(function () use ($databaseId, $collectionId, $headers) {
            $index = $this->client->call(Client::METHOD_GET, $this->getIndexUrl($databaseId, $collectionId, 'email_unique'), $headers);

            $this->assertSame(200, $index['headers']['status-code']);
            $this->assertSame('failed', $index['body']['status']);
            $this->assertNotEmpty($index['body']['error'], 'The customer learns why from the index itself, so the reason has to be on it');
        }, 120000, 500);

        // The worker moved on: the next schema job on the same collection is built.
        $next = $this->client->call(Client::METHOD_POST, $this->getSchemaUrl($databaseId, $collectionId, 'string'), $headers, [
            'key' => 'name',
            'size' => 64,
            'required' => false,
        ]);
        $this->assertSame(202, $next['headers']['status-code']);
        $this->waitForAttribute($databaseId, $collectionId, 'name');
    }
}
