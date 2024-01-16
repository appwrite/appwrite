<?php

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters\V17;
use PHPUnit\Framework\TestCase;

class V17Test extends TestCase
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

    public function createUpdateRecoveryProvider()
    {
        return [
            'remove passwordAgain' => [
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
            ]
        ];
    }


    /**
     * @dataProvider createUpdateRecoveryProvider
     */
    public function testCreateExecution(array $content, array $expected): void
    {
        $model = 'account.updateRecovery';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
