<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V20;
use PHPUnit\Framework\TestCase;

class V20Test extends TestCase
{
    protected ?V20 $filter = null;

    public function setUp(): void
    {
        $this->filter = new V20();
    }

    public function tearDown(): void
    {
        $this->filter = null;
    }

    public function documentProvider(): array
    {
        return [
            'remove $sequence from single document' => [
                [
                    'name' => 'John Doe',
                    'email' => 'john.doe@example.com',
                    '$id' => 'doc1',
                    '$databaseId' => 'db1',
                    '$collectionId' => 'col1',
                    '$createdAt' => '2025-04-09T12:00:00.000+00:00',
                    '$updatedAt' => '2025-04-10T12:00:00.000+00:00',
                    '$permissions' => [],
                    '$sequence' => '123',
                ],
                [
                    'name' => 'John Doe',
                    'email' => 'john.doe@example.com',
                    '$id' => 'doc1',
                    '$databaseId' => 'db1',
                    '$collectionId' => 'col1',
                    '$createdAt' => '2025-04-09T12:00:00.000+00:00',
                    '$updatedAt' => '2025-04-10T12:00:00.000+00:00',
                    '$permissions' => [],
                ]
            ]
        ];
    }

    /**
     * @dataProvider documentProvider
     */
    public function testParseDocument(array $content, array $expected): void
    {
        $result = $this->filter->parse($content, Response::MODEL_DOCUMENT);
        $this->assertEquals($expected, $result);
    }

    public function documentListProvider(): array
    {
        return [
            'remove $sequence from document list' => [
                [
                    'documents' => [
                        [
                            'name' => 'Alice Smith',
                            'email' => 'alice@example.com',
                            '$id' => 'doc2',
                            '$databaseId' => 'db1',
                            '$collectionId' => 'col1',
                            '$createdAt' => '2025-04-09T10:00:00.000+00:00',
                            '$updatedAt' => '2025-04-10T10:00:00.000+00:00',
                            '$permissions' => [],
                            '$sequence' => '201',
                        ],
                        [
                            'name' => 'Bob Johnson',
                            'email' => 'bob@example.com',
                            '$id' => 'doc3',
                            '$databaseId' => 'db1',
                            '$collectionId' => 'col1',
                            '$createdAt' => '2025-04-08T09:00:00.000+00:00',
                            '$updatedAt' => '2025-04-09T09:00:00.000+00:00',
                            '$permissions' => [],
                            '$sequence' => '202',
                        ]
                    ]
                ],
                [
                    'documents' => [
                        [
                            'name' => 'Alice Smith',
                            'email' => 'alice@example.com',
                            '$id' => 'doc2',
                            '$databaseId' => 'db1',
                            '$collectionId' => 'col1',
                            '$createdAt' => '2025-04-09T10:00:00.000+00:00',
                            '$updatedAt' => '2025-04-10T10:00:00.000+00:00',
                            '$permissions' => [],
                        ],
                        [
                            'name' => 'Bob Johnson',
                            'email' => 'bob@example.com',
                            '$id' => 'doc3',
                            '$databaseId' => 'db1',
                            '$collectionId' => 'col1',
                            '$createdAt' => '2025-04-08T09:00:00.000+00:00',
                            '$updatedAt' => '2025-04-09T09:00:00.000+00:00',
                            '$permissions' => [],
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * @dataProvider documentListProvider
     */
    public function testParseDocumentList(array $content, array $expected): void
    {
        $result = $this->filter->parse($content, Response::MODEL_DOCUMENT_LIST);
        $this->assertEquals($expected, $result);
    }
}
