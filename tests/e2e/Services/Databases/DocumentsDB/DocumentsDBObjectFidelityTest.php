<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\DocumentsDB;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Helpers\ID;

final class DocumentsDBObjectFidelityTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testEmptyObjectFidelity(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $databaseId = ID::unique();
        $collectionId = ID::unique();

        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', $headers, [
            'databaseId' => $databaseId,
            'name' => 'Object Fidelity',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);

        try {
            $collection = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections", $headers, [
                'collectionId' => $collectionId,
                'name' => 'Objects',
            ]);
            $this->assertSame(201, $collection['headers']['status-code']);

            $empty = $this->client->call(Client::METHOD_POST, "/documentsdb/{$databaseId}/collections/{$collectionId}/documents", $headers, [
                'documentId' => 'empty',
                'data' => new \stdClass(),
            ]);
            $this->assertSame(201, $empty['headers']['status-code']);

            $created = $this->client->call(
                Client::METHOD_POST,
                "/documentsdb/{$databaseId}/collections/{$collectionId}/documents",
                $headers,
                [
                    'documentId' => 'created',
                    'data' => ['shape' => $this->shape()],
                ],
                false,
            );
            $this->assertSame(201, $created['headers']['status-code']);
            $this->assertShape($created['body']);

            for ($read = 0; $read < 2; $read++) {
                $fetched = $this->client->call(
                    Client::METHOD_GET,
                    "/documentsdb/{$databaseId}/collections/{$collectionId}/documents/created",
                    $headers,
                    decode: false,
                );
                $this->assertSame(200, $fetched['headers']['status-code']);
                $this->assertShape($fetched['body']);
            }

            $updated = $this->client->call(
                Client::METHOD_PATCH,
                "/documentsdb/{$databaseId}/collections/{$collectionId}/documents/created",
                $headers,
                ['data' => \json_encode(['shape' => $this->shape()], JSON_THROW_ON_ERROR)],
                false,
            );
            $this->assertSame(200, $updated['headers']['status-code']);
            $this->assertShape($updated['body']);

            $upserted = $this->client->call(
                Client::METHOD_PUT,
                "/documentsdb/{$databaseId}/collections/{$collectionId}/documents/upserted",
                $headers,
                ['data' => ['shape' => $this->shape()]],
                false,
            );
            $this->assertContains($upserted['headers']['status-code'], [200, 201]);
            $this->assertShape($upserted['body']);

            $bulkCreated = $this->client->call(
                Client::METHOD_POST,
                "/documentsdb/{$databaseId}/collections/{$collectionId}/documents",
                $headers,
                ['documents' => [\json_encode(['$id' => 'bulk-created', 'shape' => $this->shape()], JSON_THROW_ON_ERROR)]],
                false,
            );
            $this->assertSame(201, $bulkCreated['headers']['status-code']);
            $this->assertShape($bulkCreated['body'], true);

            $bulkUpdated = $this->client->call(
                Client::METHOD_PATCH,
                "/documentsdb/{$databaseId}/collections/{$collectionId}/documents",
                $headers,
                ['data' => \json_encode(['shape' => $this->shape()], JSON_THROW_ON_ERROR)],
                false,
            );
            $this->assertSame(200, $bulkUpdated['headers']['status-code']);
            $this->assertShape($bulkUpdated['body'], true);

            $bulkUpserted = $this->client->call(
                Client::METHOD_PUT,
                "/documentsdb/{$databaseId}/collections/{$collectionId}/documents",
                $headers,
                ['documents' => [['$id' => 'bulk-upserted', 'shape' => $this->shape()]]],
                false,
            );
            $this->assertContains($bulkUpserted['headers']['status-code'], [200, 201]);
            $this->assertShape($bulkUpserted['body'], true);
        } finally {
            $this->client->call(Client::METHOD_DELETE, "/documentsdb/{$databaseId}", $headers);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(): array
    {
        return [
            'empty' => new \stdClass(),
            'nested' => ['empty' => new \stdClass()],
            'list' => [new \stdClass(), ['x' => 1]],
            'emptyArray' => [],
        ];
    }

    private function assertShape(string $body, bool $bulk = false): void
    {
        $decoded = \json_decode($body, flags: JSON_THROW_ON_ERROR);
        if ($bulk) {
            $decoded = $decoded->documents[0];
        }
        $shape = $decoded->shape;

        $this->assertInstanceOf(\stdClass::class, $shape->empty);
        $this->assertInstanceOf(\stdClass::class, $shape->nested->empty);
        $this->assertInstanceOf(\stdClass::class, $shape->list[0]);
        $this->assertSame(1, $shape->list[1]->x);
        $this->assertSame([], $shape->emptyArray);
    }
}
