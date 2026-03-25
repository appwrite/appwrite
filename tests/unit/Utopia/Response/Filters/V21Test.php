<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V21;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class V21Test extends TestCase
{
    protected ?V21 $filter = null;

    public function setUp(): void
    {
        $this->filter = new V21();
    }

    public function tearDown(): void
    {
    }

    public static function functionProvider(): array
    {
        return [
            'merge buildSpecification and runtimeSpecification into specification' => [
                [
                    'name' => 'test-function',
                    'buildSpecification' => 's-1vcpu-512mb',
                    'runtimeSpecification' => 's-1vcpu-256mb',
                    'runtime' => 'node-18.0',
                ],
                [
                    'name' => 'test-function',
                    'specification' => 's-1vcpu-512mb',
                    'runtime' => 'node-18.0',
                ]
            ],
            'use buildSpecification when present' => [
                [
                    'buildSpecification' => 's-2vcpu-1gb',
                    'runtimeSpecification' => 's-1vcpu-512mb',
                ],
                [
                    'specification' => 's-2vcpu-1gb',
                ]
            ],
            'fallback to specification when buildSpecification is missing' => [
                [
                    'specification' => 's-1vcpu-512mb',
                    'runtimeSpecification' => 's-1vcpu-256mb',
                ],
                [
                    'specification' => 's-1vcpu-512mb',
                ]
            ],
            'handle missing both buildSpecification and specification' => [
                [
                    'name' => 'test-function',
                    'runtimeSpecification' => 's-1vcpu-256mb',
                ],
                [
                    'name' => 'test-function',
                    'specification' => null,
                ]
            ],
            'handle no spec fields at all' => [
                [
                    'name' => 'test-function',
                    'runtime' => 'node-18.0',
                ],
                [
                    'name' => 'test-function',
                    'specification' => null,
                    'runtime' => 'node-18.0',
                ]
            ],
            'handle empty buildSpecification string' => [
                [
                    'buildSpecification' => '',
                    'runtimeSpecification' => 's-1vcpu-256mb',
                ],
                [
                    'specification' => '',
                ]
            ],
        ];
    }

    #[DataProvider('functionProvider')]
    public function testFunction(array $content, array $expected): void
    {
        $model = Response::MODEL_FUNCTION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function functionListProvider(): array
    {
        return [
            'convert list of functions' => [
                [
                    'total' => 2,
                    'functions' => [
                        [
                            'name' => 'function-1',
                            'buildSpecification' => 's-1vcpu-512mb',
                            'runtimeSpecification' => 's-1vcpu-256mb',
                        ],
                        [
                            'name' => 'function-2',
                            'buildSpecification' => 's-2vcpu-1gb',
                            'runtimeSpecification' => 's-1vcpu-512mb',
                        ],
                    ],
                ],
                [
                    'total' => 2,
                    'functions' => [
                        [
                            'name' => 'function-1',
                            'specification' => 's-1vcpu-512mb',
                        ],
                        [
                            'name' => 'function-2',
                            'specification' => 's-2vcpu-1gb',
                        ],
                    ],
                ]
            ],
            'handle empty function list' => [
                [
                    'total' => 0,
                    'functions' => [],
                ],
                [
                    'total' => 0,
                    'functions' => [],
                ]
            ],
            'handle single function in list' => [
                [
                    'total' => 1,
                    'functions' => [
                        [
                            'buildSpecification' => 's-1vcpu-512mb',
                            'runtimeSpecification' => 's-1vcpu-256mb',
                        ],
                    ],
                ],
                [
                    'total' => 1,
                    'functions' => [
                        [
                            'specification' => 's-1vcpu-512mb',
                        ],
                    ],
                ]
            ],
        ];
    }

    #[DataProvider('functionListProvider')]
    public function testFunctionList(array $content, array $expected): void
    {
        $model = Response::MODEL_FUNCTION_LIST;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function siteProvider(): array
    {
        return [
            'merge buildSpecification and runtimeSpecification into specification' => [
                [
                    'name' => 'test-site',
                    'buildSpecification' => 's-1vcpu-512mb',
                    'runtimeSpecification' => 's-1vcpu-256mb',
                    'framework' => 'nextjs',
                ],
                [
                    'name' => 'test-site',
                    'specification' => 's-1vcpu-512mb',
                    'framework' => 'nextjs',
                ]
            ],
            'use buildSpecification when present' => [
                [
                    'buildSpecification' => 's-2vcpu-1gb',
                    'runtimeSpecification' => 's-1vcpu-512mb',
                ],
                [
                    'specification' => 's-2vcpu-1gb',
                ]
            ],
            'fallback to specification when buildSpecification is missing' => [
                [
                    'specification' => 's-1vcpu-512mb',
                    'runtimeSpecification' => 's-1vcpu-256mb',
                ],
                [
                    'specification' => 's-1vcpu-512mb',
                ]
            ],
            'handle missing both buildSpecification and specification' => [
                [
                    'name' => 'test-site',
                    'runtimeSpecification' => 's-1vcpu-256mb',
                ],
                [
                    'name' => 'test-site',
                    'specification' => null,
                ]
            ],
            'handle no spec fields at all' => [
                [
                    'name' => 'test-site',
                    'framework' => 'react',
                ],
                [
                    'name' => 'test-site',
                    'specification' => null,
                    'framework' => 'react',
                ]
            ],
        ];
    }

    #[DataProvider('siteProvider')]
    public function testSite(array $content, array $expected): void
    {
        $model = Response::MODEL_SITE;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function siteListProvider(): array
    {
        return [
            'convert list of sites' => [
                [
                    'total' => 2,
                    'sites' => [
                        [
                            'name' => 'site-1',
                            'buildSpecification' => 's-1vcpu-512mb',
                            'runtimeSpecification' => 's-1vcpu-256mb',
                        ],
                        [
                            'name' => 'site-2',
                            'buildSpecification' => 's-2vcpu-1gb',
                            'runtimeSpecification' => 's-1vcpu-512mb',
                        ],
                    ],
                ],
                [
                    'total' => 2,
                    'sites' => [
                        [
                            'name' => 'site-1',
                            'specification' => 's-1vcpu-512mb',
                        ],
                        [
                            'name' => 'site-2',
                            'specification' => 's-2vcpu-1gb',
                        ],
                    ],
                ]
            ],
            'handle empty site list' => [
                [
                    'total' => 0,
                    'sites' => [],
                ],
                [
                    'total' => 0,
                    'sites' => [],
                ]
            ],
            'handle single site in list' => [
                [
                    'total' => 1,
                    'sites' => [
                        [
                            'name' => 'my-site',
                            'buildSpecification' => 's-1vcpu-512mb',
                            'runtimeSpecification' => 's-1vcpu-256mb',
                        ],
                    ],
                ],
                [
                    'total' => 1,
                    'sites' => [
                        [
                            'name' => 'my-site',
                            'specification' => 's-1vcpu-512mb',
                        ],
                    ],
                ]
            ],
        ];
    }

    #[DataProvider('siteListProvider')]
    public function testSiteList(array $content, array $expected): void
    {
        $model = Response::MODEL_SITE_LIST;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function documentProvider(): array
    {
        return [
            'cast $sequence to int' => [
                [
                    '$id' => 'doc1',
                    '$sequence' => '123',
                    'name' => 'test',
                ],
                [
                    '$id' => 'doc1',
                    '$sequence' => 123,
                    'name' => 'test',
                ]
            ],
            'non-numeric $sequence becomes 0' => [
                [
                    '$id' => 'doc1',
                    '$sequence' => 'abc',
                ],
                [
                    '$id' => 'doc1',
                    '$sequence' => 0,
                ]
            ],
            'nested relationship document' => [
                [
                    '$id' => 'doc1',
                    '$sequence' => '1',
                    'author' => [
                        '$id' => 'doc2',
                        '$sequence' => '2',
                        'name' => 'John',
                    ],
                ],
                [
                    '$id' => 'doc1',
                    '$sequence' => 1,
                    'author' => [
                        '$id' => 'doc2',
                        '$sequence' => 2,
                        'name' => 'John',
                    ],
                ]
            ],
            'nested array of relationship documents' => [
                [
                    '$id' => 'doc1',
                    '$sequence' => '1',
                    'comments' => [
                        [
                            '$id' => 'doc2',
                            '$sequence' => '2',
                            'text' => 'hello',
                        ],
                        [
                            '$id' => 'doc3',
                            '$sequence' => '3',
                            'text' => 'world',
                        ],
                    ],
                ],
                [
                    '$id' => 'doc1',
                    '$sequence' => 1,
                    'comments' => [
                        [
                            '$id' => 'doc2',
                            '$sequence' => 2,
                            'text' => 'hello',
                        ],
                        [
                            '$id' => 'doc3',
                            '$sequence' => 3,
                            'text' => 'world',
                        ],
                    ],
                ]
            ],
            'deeply nested relationships' => [
                [
                    '$id' => 'doc1',
                    '$sequence' => '1',
                    'author' => [
                        '$id' => 'doc2',
                        '$sequence' => '2',
                        'profile' => [
                            '$id' => 'doc3',
                            '$sequence' => '3',
                        ],
                    ],
                ],
                [
                    '$id' => 'doc1',
                    '$sequence' => 1,
                    'author' => [
                        '$id' => 'doc2',
                        '$sequence' => 2,
                        'profile' => [
                            '$id' => 'doc3',
                            '$sequence' => 3,
                        ],
                    ],
                ]
            ],
        ];
    }

    #[DataProvider('documentProvider')]
    public function testDocument(array $content, array $expected): void
    {
        $result = $this->filter->parse($content, Response::MODEL_DOCUMENT);

        $this->assertSame($expected, $result);
    }

    #[DataProvider('documentProvider')]
    public function testRow(array $content, array $expected): void
    {
        $result = $this->filter->parse($content, Response::MODEL_ROW);

        $this->assertSame($expected, $result);
    }

    public static function documentListProvider(): array
    {
        return [
            'cast $sequence in document list' => [
                [
                    'total' => 2,
                    'documents' => [
                        [
                            '$id' => 'doc1',
                            '$sequence' => '10',
                            'name' => 'first',
                        ],
                        [
                            '$id' => 'doc2',
                            '$sequence' => '20',
                            'name' => 'second',
                        ],
                    ],
                ],
                [
                    'total' => 2,
                    'documents' => [
                        [
                            '$id' => 'doc1',
                            '$sequence' => 10,
                            'name' => 'first',
                        ],
                        [
                            '$id' => 'doc2',
                            '$sequence' => 20,
                            'name' => 'second',
                        ],
                    ],
                ]
            ],
            'handle empty document list' => [
                [
                    'total' => 0,
                    'documents' => [],
                ],
                [
                    'total' => 0,
                    'documents' => [],
                ]
            ],
        ];
    }

    #[DataProvider('documentListProvider')]
    public function testDocumentList(array $content, array $expected): void
    {
        $result = $this->filter->parse($content, Response::MODEL_DOCUMENT_LIST);

        $this->assertSame($expected, $result);
    }

    #[DataProvider('documentListProvider')]
    public function testRowList(array $content, array $expected): void
    {
        $content['rows'] = $content['documents'];
        unset($content['documents']);
        $expected['rows'] = $expected['documents'];
        unset($expected['documents']);

        $result = $this->filter->parse($content, Response::MODEL_ROW_LIST);

        $this->assertSame($expected, $result);
    }

    public static function defaultPassthroughProvider(): array
    {
        return [
            'unmatched model passes through unchanged' => [
                Response::MODEL_DOCUMENT,
                [
                    'name' => 'test-doc',
                    '$id' => 'doc123',
                    'data' => 'some-value',
                ],
                [
                    'name' => 'test-doc',
                    '$id' => 'doc123',
                    'data' => 'some-value',
                ]
            ],
            'empty content passes through unchanged' => [
                Response::MODEL_DOCUMENT,
                [],
                []
            ],
            'deployment model passes through unchanged' => [
                Response::MODEL_DEPLOYMENT,
                [
                    'id' => 'deployment123',
                    'buildSpecification' => 's-1vcpu-512mb',
                    'runtimeSpecification' => 's-1vcpu-256mb',
                ],
                [
                    'id' => 'deployment123',
                    'buildSpecification' => 's-1vcpu-512mb',
                    'runtimeSpecification' => 's-1vcpu-256mb',
                ]
            ],
        ];
    }

    #[DataProvider('defaultPassthroughProvider')]
    public function testDefaultPassthrough(string $model, array $content, array $expected): void
    {
        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
