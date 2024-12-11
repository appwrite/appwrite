<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V17;
use PHPUnit\Framework\TestCase;

class V17Test extends TestCase
{
    /**
     * @var Filter
     */
    protected $filter = null;

    public function setUp(): void
    {
        $this->filter = new V17();
    }

    public function tearDown(): void
    {
    }

    public function projectProvider(): array
    {
        return [
            'rename providers' => [
                [
                    'oAuthProviders' => [
                        [
                            'key' => 'github',
                            'name' => 'GitHub',
                            'appId' => 'client_id',
                            'secret' => 'client_secret',
                            'enabled' => true,
                        ],
                    ],
                ],
                [
                    'providers' => [
                        [
                            'key' => 'github',
                            'name' => 'GitHub',
                            'appId' => 'client_id',
                            'secret' => 'client_secret',
                            'enabled' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @dataProvider projectProvider
     */
    public function testProject(array $content, array $expected): void
    {
        $model = Response::MODEL_PROJECT;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function userProvider(): array
    {
        return [
            'remove targets' => [
                [
                    'targets' => 'test',
                    'mfa' => 'test',
                ],
                [
                ],
            ],
        ];
    }

    /**
     * @dataProvider userProvider
     */
    public function testUser(array $content, array $expected): void
    {
        $model = Response::MODEL_USER;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function tokenProvider(): array
    {
        return [
            'remove securityPhrase' => [
                [
                    'phrase' => 'Lorum Ipsum',
                ],
                [
                ],
            ],
        ];
    }

    /**
     * @dataProvider tokenProvider
     */
    public function testToken(array $content, array $expected): void
    {
        $model = Response::MODEL_TOKEN;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function membershipProvider(): array
    {
        return [
            'remove mfa' => [
                [
                    'mfa' => 'test',
                ],
                [
                ],
            ],
        ];
    }

    /**
     * @dataProvider membershipProvider
     */
    public function testMembership(array $content, array $expected): void
    {
        $model = Response::MODEL_MEMBERSHIP;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function sessionProvider(): array
    {
        return [
            'remove factors and secrets' => [
                [
                    'factors' => 'test',
                    'secret' => 'test',
                ],
                [
                ],
            ]
        ];
    }

    /**
     * @dataProvider sessionProvider
     */
    public function testSession(array $content, array $expected): void
    {
        $model = Response::MODEL_SESSION;

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public function webhookProvider(): array
    {
        return [
            'remove webhook additions' => [
                [
                    'enabled' => true,
                    'logs' => ['test', 'test'],
                    'attempts' => 1
                ],
                [
                ],
            ],
        ];
    }
}
