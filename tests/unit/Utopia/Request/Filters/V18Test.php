<?php

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters\V18;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class V18Test extends TestCase
{
    /**
     * @var Filter
     */
    protected $filter;

    public function setUp(): void
    {
        $this->filter = new V18();
    }

    public function tearDown(): void
    {
    }

    public static function deleteMfaAuthenticatorProvider(): array
    {
        return [
            'remove otp' => [
                [
                    'type' => 'totp',
                    'otp' => 1230
                ],
                [
                    'type' => 'totp'
                ]
            ]
        ];
    }

    #[DataProvider('deleteMfaAuthenticatorProvider')]
    public function testdeleteMfaAuthenticator(array $content, array $expected): void
    {
        $model = 'account.deleteMfaAuthenticator';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
