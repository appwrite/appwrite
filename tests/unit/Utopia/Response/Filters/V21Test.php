<?php

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V21;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class V21Test extends TestCase
{
    /**
     * @var Filter
     */
    protected $filter = null;

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
            'convert buildSpecification to specification and remove new fields' => [
                [
                    'buildSpecification' => 's-1vcpu-512mb',
                    'runtimeSpecification' => 's-1vcpu-512mb',
                    'deploymentRetention' => 7,
                    'name' => 'my-function',
                ],
                [
                    'specification' => 's-1vcpu-512mb',
                    'name' => 'my-function',
                ]
            ],
            'handle missing buildSpecification' => [
                [
                    'deploymentRetention' => 0,
                    'name' => 'my-function',
                ],
                [
                    'specification' => null,
                    'name' => 'my-function',
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
                            'buildSpecification' => 's-1vcpu-512mb',
                            'runtimeSpecification' => 's-1vcpu-512mb',
                            'deploymentRetention' => 7,
                            'name' => 'function-1',
                        ],
                        [
                            'buildSpecification' => 's-2vcpu-1gb',
                            'runtimeSpecification' => 's-1vcpu-512mb',
                            'deploymentRetention' => 0,
                            'name' => 'function-2',
                        ],
                    ],
                ],
                [
                    'total' => 2,
                    'functions' => [
                        [
                            'specification' => 's-1vcpu-512mb',
                            'name' => 'function-1',
                        ],
                        [
                            'specification' => 's-2vcpu-1gb',
                            'name' => 'function-2',
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
            'convert buildSpecification to specification and remove new fields' => [
                [
                    'buildSpecification' => 's-1vcpu-512mb',
                    'runtimeSpecification' => 's-1vcpu-512mb',
                    'deploymentRetention' => 7,
                    'startCommand' => 'node custom-server.js',
                    'name' => 'my-site',
                ],
                [
                    'specification' => 's-1vcpu-512mb',
                    'name' => 'my-site',
                ]
            ],
            'handle missing buildSpecification' => [
                [
                    'deploymentRetention' => 0,
                    'startCommand' => '',
                    'name' => 'my-site',
                ],
                [
                    'specification' => null,
                    'name' => 'my-site',
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
                    'total' => 1,
                    'sites' => [
                        [
                            'buildSpecification' => 's-1vcpu-512mb',
                            'runtimeSpecification' => 's-1vcpu-512mb',
                            'deploymentRetention' => 7,
                            'startCommand' => 'node custom-server.js',
                            'name' => 'site-1',
                        ],
                    ],
                ],
                [
                    'total' => 1,
                    'sites' => [
                        [
                            'specification' => 's-1vcpu-512mb',
                            'name' => 'site-1',
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
}
