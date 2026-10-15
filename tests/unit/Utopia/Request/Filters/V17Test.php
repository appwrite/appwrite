<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters\V17;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class V17Test extends TestCase
{
    /**
     * @var Filter
     */
    protected $filter;

    public function setUp(): void
    {
        $this->filter = new V17();
    }

    public function tearDown(): void
    {
    }

    public static function createUpdateRecoveryProvider(): \Iterator
    {
        yield 'remove passwordAgain' => [
            [
                'userId' => 'test',
                'secret' => 'test',
                'password' => '123456',
                'passwordAgain' => '123456'
            ],
            [
                'userId' => 'test',
                'secret' => 'test',
                'password' => '123456',
            ]
        ];
    }

    #[DataProvider('createUpdateRecoveryProvider')]
    public function testUpdateRecovery(array $content, array $expected): void
    {
        $model = 'account.updateRecovery';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function createQueryProvider(): \Iterator
    {
        yield 'convert queries' => [
            [
                'queries' => [
                    'cursorAfter("exampleId")',
                    'search("name", ["example"])',
                    'isNotNull("name")'
                ]
            ],
            [
                'queries' => [
                    '{"method":"cursorAfter","values":["exampleId"]}',
                    '{"method":"search","attribute":"name","values":["example"]}',
                    '{"method":"isNotNull","attribute":"name"}'
                ]
            ],
        ];

        yield 'keep unknown method for endpoint validation' => [
            [
                'queries' => [
                    'bogus("title", "x")',
                    'equal("title", ["x"])',
                ]
            ],
            [
                'queries' => [
                    '{"method":"bogus"}',
                    '{"method":"equal","attribute":"title","values":["x"]}',
                ]
            ],
        ];
    }

    #[DataProvider('createQueryProvider')]
    public function testQuery(array $content, array $expected): void
    {
        $model = 'databases.getDocument';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function testRejectsDeprecatedDottedMethod(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid query method');

        $this->filter->parse(['queries' => ['title.equal("x")']], 'databases.listDocuments');
    }
}
