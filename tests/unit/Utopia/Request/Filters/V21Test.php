<?php

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Appwrite\Utopia\Request\Filters\V21;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class V21Test extends TestCase
{
    /**
     * @var Filter
     */
    protected $filter;

    public function setUp(): void
    {
        $this->filter = new V21();
    }

    public function tearDown(): void
    {
    }

    public static function functionsCreateTemplateDeploymentProvider(): array
    {
        return [
            'convert version to type and reference' => [
                [
                    'templateId' => 'template123',
                    'version' => '1.0.0',
                ],
                [
                    'templateId' => 'template123',
                    'type' => 'tag',
                    'reference' => '1.0.0',
                ]
            ],
            'handle version with semver string' => [
                [
                    'version' => 'v2.3.1',
                    'templateId' => 'template456',
                ],
                [
                    'type' => 'tag',
                    'reference' => 'v2.3.1',
                    'templateId' => 'template456',
                ]
            ],
            'skip conversion when version is empty string' => [
                [
                    'templateId' => 'template123',
                    'version' => '',
                ],
                [
                    'templateId' => 'template123',
                    'version' => '',
                ]
            ],
            'skip conversion when version is missing' => [
                [
                    'templateId' => 'template123',
                ],
                [
                    'templateId' => 'template123',
                ]
            ],
            'preserve other fields when converting version' => [
                [
                    'templateId' => 'template123',
                    'version' => '3.0.0',
                    'activate' => true,
                    'buildCommand' => 'npm run build',
                ],
                [
                    'templateId' => 'template123',
                    'type' => 'tag',
                    'reference' => '3.0.0',
                    'activate' => true,
                    'buildCommand' => 'npm run build',
                ]
            ],
        ];
    }

    #[DataProvider('functionsCreateTemplateDeploymentProvider')]
    public function testFunctionsCreateTemplateDeployment(array $content, array $expected): void
    {
        $model = 'functions.createTemplateDeployment';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function sitesCreateTemplateDeploymentProvider(): array
    {
        return [
            'convert version to type and reference' => [
                [
                    'templateId' => 'site-template123',
                    'version' => '2.0.0',
                ],
                [
                    'templateId' => 'site-template123',
                    'type' => 'tag',
                    'reference' => '2.0.0',
                ]
            ],
            'skip conversion when version is empty' => [
                [
                    'templateId' => 'site-template123',
                    'version' => '',
                ],
                [
                    'templateId' => 'site-template123',
                    'version' => '',
                ]
            ],
            'skip conversion when version is missing' => [
                [
                    'templateId' => 'site-template123',
                ],
                [
                    'templateId' => 'site-template123',
                ]
            ],
        ];
    }

    #[DataProvider('sitesCreateTemplateDeploymentProvider')]
    public function testSitesCreateTemplateDeployment(array $content, array $expected): void
    {
        $model = 'sites.createTemplateDeployment';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function functionsCreateProvider(): array
    {
        return [
            'convert specification to buildSpecification and runtimeSpecification' => [
                [
                    'name' => 'test-function',
                    'runtime' => 'node-18.0',
                    'specification' => 's-1vcpu-512mb',
                ],
                [
                    'name' => 'test-function',
                    'runtime' => 'node-18.0',
                    'buildSpecification' => 's-1vcpu-512mb',
                    'runtimeSpecification' => 's-1vcpu-512mb',
                ]
            ],
            'skip conversion when specification is empty' => [
                [
                    'name' => 'test-function',
                    'runtime' => 'node-18.0',
                    'specification' => '',
                ],
                [
                    'name' => 'test-function',
                    'runtime' => 'node-18.0',
                    'specification' => '',
                ]
            ],
            'skip conversion when specification is missing' => [
                [
                    'name' => 'test-function',
                    'runtime' => 'node-18.0',
                ],
                [
                    'name' => 'test-function',
                    'runtime' => 'node-18.0',
                ]
            ],
            'preserve other fields when converting specification' => [
                [
                    'name' => 'test-function',
                    'specification' => 's-2vcpu-1gb',
                    'timeout' => 30,
                    'enabled' => true,
                ],
                [
                    'name' => 'test-function',
                    'buildSpecification' => 's-2vcpu-1gb',
                    'runtimeSpecification' => 's-2vcpu-1gb',
                    'timeout' => 30,
                    'enabled' => true,
                ]
            ],
        ];
    }

    #[DataProvider('functionsCreateProvider')]
    public function testFunctionsCreate(array $content, array $expected): void
    {
        $model = 'functions.create';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function sitesCreateProvider(): array
    {
        return [
            'convert specification to buildSpecification and runtimeSpecification' => [
                [
                    'name' => 'test-site',
                    'framework' => 'nextjs',
                    'specification' => 's-1vcpu-512mb',
                ],
                [
                    'name' => 'test-site',
                    'framework' => 'nextjs',
                    'buildSpecification' => 's-1vcpu-512mb',
                    'runtimeSpecification' => 's-1vcpu-512mb',
                ]
            ],
            'skip conversion when specification is empty' => [
                [
                    'name' => 'test-site',
                    'specification' => '',
                ],
                [
                    'name' => 'test-site',
                    'specification' => '',
                ]
            ],
            'skip conversion when specification is missing' => [
                [
                    'name' => 'test-site',
                ],
                [
                    'name' => 'test-site',
                ]
            ],
        ];
    }

    #[DataProvider('sitesCreateProvider')]
    public function testSitesCreate(array $content, array $expected): void
    {
        $model = 'sites.create';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function functionsUpdateProvider(): array
    {
        return [
            'convert specification to buildSpecification and runtimeSpecification' => [
                [
                    'name' => 'updated-function',
                    'specification' => 's-2vcpu-1gb',
                ],
                [
                    'name' => 'updated-function',
                    'buildSpecification' => 's-2vcpu-1gb',
                    'runtimeSpecification' => 's-2vcpu-1gb',
                ]
            ],
            'skip conversion when specification is missing' => [
                [
                    'name' => 'updated-function',
                ],
                [
                    'name' => 'updated-function',
                ]
            ],
        ];
    }

    #[DataProvider('functionsUpdateProvider')]
    public function testFunctionsUpdate(array $content, array $expected): void
    {
        $model = 'functions.update';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function sitesUpdateProvider(): array
    {
        return [
            'convert specification to buildSpecification and runtimeSpecification' => [
                [
                    'name' => 'updated-site',
                    'specification' => 's-2vcpu-1gb',
                ],
                [
                    'name' => 'updated-site',
                    'buildSpecification' => 's-2vcpu-1gb',
                    'runtimeSpecification' => 's-2vcpu-1gb',
                ]
            ],
            'skip conversion when specification is missing' => [
                [
                    'name' => 'updated-site',
                ],
                [
                    'name' => 'updated-site',
                ]
            ],
        ];
    }

    #[DataProvider('sitesUpdateProvider')]
    public function testSitesUpdate(array $content, array $expected): void
    {
        $model = 'sites.update';

        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }

    public static function unmatchedModelProvider(): array
    {
        return [
            'unmatched model passes through unchanged' => [
                'databases.create',
                [
                    'name' => 'test-database',
                    'databaseId' => 'db123',
                ],
                [
                    'name' => 'test-database',
                    'databaseId' => 'db123',
                ]
            ],
            'empty content for unmatched model' => [
                'users.list',
                [],
                []
            ],
            'content with specification for unmatched model is not converted' => [
                'deployments.create',
                [
                    'specification' => 's-1vcpu-512mb',
                    'name' => 'test',
                ],
                [
                    'specification' => 's-1vcpu-512mb',
                    'name' => 'test',
                ]
            ],
            'content with version for unmatched model is not converted' => [
                'databases.createDocument',
                [
                    'version' => '1.0.0',
                    'data' => 'test',
                ],
                [
                    'version' => '1.0.0',
                    'data' => 'test',
                ]
            ],
        ];
    }

    #[DataProvider('unmatchedModelProvider')]
    public function testUnmatchedModel(string $model, array $content, array $expected): void
    {
        $result = $this->filter->parse($content, $model);

        $this->assertEquals($expected, $result);
    }
}
