<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ApiLegacy;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Query;

final class LegacyCustomServerTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideServer;
    use ApiLegacy;

    public function testListDocumentsRejectsInvalidLegacyQueryType(): void
    {
        $response = $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl('database', 'collection') . '?queries=invalid',
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-response-format' => '1.7.4',
            ], $this->getHeaders())
        );

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_ARGUMENT_INVALID, $response['body']['type']);
    }

    public function testListDocumentsPaginatesByCursorForLegacyResponseFormats(): void
    {
        $data = $this->setupDocuments();
        $ids = $data['documentIds'];

        $base = $this->listDocumentsAs('1.9.0', $data, [
            Query::equal('$id', $ids)->toString(),
        ]);

        $this->assertSame(200, $base['headers']['status-code'], \json_encode($base['body']));
        $ordered = \array_column($base['body']['documents'], '$id');
        $this->assertCount(3, $ordered);

        $quotedIds = \implode(', ', \array_map(fn (string $id): string => '"' . $id . '"', $ids));

        $requests = [
            '1.7.0' => [
                Query::equal('$id', $ids)->toString(),
                '{"method":"cursorAfter","values":["' . $ordered[0] . '"]}',
            ],
            '1.5.0' => [
                Query::equal('$id', $ids)->toString(),
                '{"method":"cursorAfter","values":["' . $ordered[0] . '"]}',
            ],
            '1.4.0' => [
                'equal("$id", [' . $quotedIds . '])',
                'cursorAfter("' . $ordered[0] . '")',
            ],
        ];

        foreach ($requests as $format => $queries) {
            $documents = $this->listDocumentsAs($format, $data, $queries);

            $this->assertSame(200, $documents['headers']['status-code'], $format . ': ' . \json_encode($documents['body']));
            $this->assertSame(\array_slice($ordered, 1), \array_column($documents['body']['documents'], '$id'), $format);
        }

        $documents = $this->listDocumentsAs('1.7.0', $data, [
            Query::equal('$id', $ids)->toString(),
            '{"method":"cursorBefore","values":["' . $ordered[2] . '"]}',
        ]);

        $this->assertSame(200, $documents['headers']['status-code'], \json_encode($documents['body']));
        $this->assertSame(\array_slice($ordered, 0, 2), \array_column($documents['body']['documents'], '$id'));
    }

    public function testListDocumentsRejectsUnknownLegacyQueryMethod(): void
    {
        $data = $this->setupDocuments();

        $documents = $this->listDocumentsAs('1.4.0', $data, ['bogus("title", "x")']);

        $this->assertSame(400, $documents['headers']['status-code']);
        $this->assertSame(Exception::GENERAL_QUERY_INVALID, $documents['body']['type']);
        $this->assertStringContainsString('Invalid query method: bogus', (string) $documents['body']['message']);
        $this->assertStringNotContainsString('enum', (string) $documents['body']['message']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string> $queries
     * @return array<string, mixed>
     */
    private function listDocumentsAs(string $format, array $data, array $queries): array
    {
        return $this->client->call(
            Client::METHOD_GET,
            $this->getRecordUrl($data['databaseId'], $data['moviesId']),
            array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-response-format' => $format,
            ], $this->getHeaders()),
            ['queries' => $queries]
        );
    }
}
