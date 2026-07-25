<?php

namespace Tests\Unit\SDK\Specification;

use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Format;
use Appwrite\SDK\Specification\Format\OpenAPI3;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\AlgoArgon2;
use Appwrite\Utopia\Response\Model\AlgoBcrypt;
use Appwrite\Utopia\Response\Model\AlgoMd5;
use Appwrite\Utopia\Response\Model\AlgoPhpass;
use Appwrite\Utopia\Response\Model\AlgoScrypt;
use Appwrite\Utopia\Response\Model\AlgoScryptModified;
use Appwrite\Utopia\Response\Model\AlgoSha;
use Appwrite\Utopia\Response\Model\AttributeLine;
use Appwrite\Utopia\Response\Model\Error as ErrorModel;
use Appwrite\Utopia\Response\Model\HealthStatus;
use Appwrite\Utopia\Response\Model\None as NoneModel;
use Appwrite\Utopia\Response\Model\PlatformAndroid;
use Appwrite\Utopia\Response\Model\PlatformApple;
use Appwrite\Utopia\Response\Model\PlatformLinux;
use Appwrite\Utopia\Response\Model\PlatformList;
use Appwrite\Utopia\Response\Model\PlatformWeb;
use Appwrite\Utopia\Response\Model\PlatformWindows;
use Appwrite\Utopia\Response\Model\Preferences;
use Appwrite\Utopia\Response\Model\Provider;
use Appwrite\Utopia\Response\Model\Team;
use Appwrite\Utopia\Response\Model\User;
use Appwrite\Utopia\Response\Model\Webhook;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Validator\Spatial;
use Utopia\DI\Container;
use Utopia\Http\Route;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class TestFormat extends Format
{
    public function getName(): string
    {
        return 'test';
    }

    public function parse(): array
    {
        return [];
    }

    public function requestParameterConfig(bool $optional, bool $nullable, mixed $default, string $methodName = '', string $paramName = ''): array
    {
        return $this->getRequestParameterConfig($optional, $nullable, $default, $methodName, $paramName);
    }

    public function arrayItemsSchema(mixed $example): array
    {
        return $this->getArrayItemsSchema($example);
    }
}

final class FormatTest extends TestCase
{
    private TestFormat $format;

    protected function setUp(): void
    {
        parent::setUp();

        $this->format = new TestFormat(new Container(), [], [], [], [], 0, 'console');
    }

    public function testProjectRequestParameterOverrides(): void
    {
        $createWebPlatform = $this->format->requestParameterConfig(true, false, '', 'project.createWebPlatform', 'hostname');
        $updateWebPlatform = $this->format->requestParameterConfig(true, false, '', 'project.updateWebPlatform', 'hostname');
        $listPlatforms = $this->format->requestParameterConfig(true, false, [], 'project.listPlatforms', 'queries');

        $this->assertTrue($createWebPlatform['required']);
        $this->assertFalse($createWebPlatform['emitDefault']);
        $this->assertTrue($updateWebPlatform['required']);
        $this->assertFalse($updateWebPlatform['emitDefault']);
        $this->assertTrue($listPlatforms['emitDefault']);
    }

    public function testProjectPlatformResponseTypeUsesSharedEnumMetadata(): void
    {
        $models = [
            new PlatformAndroid(),
            new PlatformWeb(),
            new PlatformApple(),
            new PlatformWindows(),
            new PlatformLinux(),
        ];

        foreach ($models as $model) {
            $this->assertSame('PlatformType', $model->getRules()['type']['enumSDKName']);
        }

        $this->assertArrayNotHasKey('enumSDKName', (new PlatformList())->getRules()['platforms']);
    }

    public function testExistingResponseEnumMetadataRemainsUnchanged(): void
    {
        $this->assertSame('HealthCheckStatus', (new HealthStatus())->getRules()['status']['enumSDKName']);
    }

    public function testOpenApiCustomIdBodyFieldIncludesIdGeneratorMetadata(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('POST', '/v1/tests'))
            ->desc('Create test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'createTest',
                description: 'Create test.',
                auth: [],
                responses: [],
            ))
            ->param('userId', '', new CustomId(), 'User ID.');

        $spec = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();

        $this->assertSame(
            ['idGenerator' => 'ID.unique'],
            $spec['paths']['/tests']['post']['requestBody']['content']['application/json']['schema']['properties']['userId']['x-appwrite']
        );
    }

    public function testMethodParameterOverridesFilterAndReplaceRouteParams(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('POST', '/v1/tests'))
            ->desc('Create test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'createTestWithOverrides',
                description: 'Create test.',
                auth: [],
                responses: [],
                parameters: [
                    new Parameter('engine', hide: true),
                    new Parameter('name', description: 'Overridden description.'),
                ],
            ))
            ->param('name', '', new Text(128), 'Original description.')
            ->param('engine', 'mysql', new Text(16), 'Engine.', true);

        $spec = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();

        $properties = $spec['paths']['/tests']['post']['requestBody']['content']['application/json']['schema']['properties'];

        $this->assertArrayNotHasKey('engine', $properties);
        $this->assertSame('Overridden description.', $properties['name']['description']);
        $this->assertSame(['name'], $spec['paths']['/tests']['post']['requestBody']['content']['application/json']['schema']['required']);
    }

    public function testMethodParameterNullDefaultOverridesRouteDefault(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $method = new Method(
            namespace: 'test',
            group: null,
            name: 'createTestWithNullDefault',
            description: 'Create test.',
            auth: [],
            responses: [],
            parameters: [
                new Parameter('engine', default: null),
                new Parameter('name', description: 'Overridden description.', optional: false),
            ],
        );

        $route = (new Route('POST', '/v1/tests'))
            ->desc('Create test')
            ->param('name', 'default-name', new Text(128), 'Original description.', true)
            ->param('engine', 'mysql', new Text(16), 'Engine.', true);

        $format = new class (new Container(), [], [], [], [], 0, 'console') extends OpenAPI3 {
            /**
             * @return array<string, array<string, mixed>>
             */
            public function methodParameters(Route $route, Method $method): array
            {
                return $this->getMethodParameters($route, $method);
            }
        };

        $parameters = $format->methodParameters($route, $method);

        $this->assertNull($parameters['engine']['default']);
        $this->assertTrue($parameters['engine']['optional']);
        $this->assertSame('default-name', $parameters['name']['default']);
        $this->assertSame('Overridden description.', $parameters['name']['description']);
        $this->assertFalse($parameters['name']['optional']);
    }

    public function testDeleteRouteOptionalParamsAreQueryParams(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('DELETE', '/v1/tests/:testId'))
            ->desc('Delete test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'deleteTest',
                description: 'Delete test.',
                auth: [],
                responses: [],
            ))
            ->param('testId', '', new Text(256), 'Test ID.')
            ->param('transactionId', null, new Nullable(new Text(256)), 'Transaction ID.', true);

        $openApi = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();

        $this->assertArrayNotHasKey('requestBody', $openApi['paths']['/tests/{testId}']['delete']);
        $this->assertCount(2, $openApi['paths']['/tests/{testId}']['delete']['parameters']);
        $this->assertSame('path', $openApi['paths']['/tests/{testId}']['delete']['parameters'][0]['in']);
        $this->assertSame('transactionId', $openApi['paths']['/tests/{testId}']['delete']['parameters'][1]['name']);
        $this->assertSame('query', $openApi['paths']['/tests/{testId}']['delete']['parameters'][1]['in']);

    }

    public function testMultiMethodRouteEmitsEveryOperation(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new class (['GET', 'POST'], '/v1/tests/:testId') extends Route {
            public function getMethods(): array
            {
                return [2 => 'GET', 4 => 'POST'];
            }
        })
            ->desc('Get or update test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'getOrUpdateTest',
                description: 'Get or update test.',
                auth: [],
                responses: [],
            ))
            ->param('testId', '', new Text(256), 'Test ID.')
            ->param('name', null, new Nullable(new Text(256)), 'Test name.', true);

        $openApi = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();

        $get = $openApi['paths']['/tests/{testId}']['get'];
        $post = $openApi['paths']['/tests/{testId}']['post'];

        $this->assertSame('testGetOrUpdateTestGet', $get['operationId']);
        $this->assertSame('testGetOrUpdateTestPost', $post['operationId']);
        $this->assertSame('getOrUpdateTest', $get['x-appwrite']['method']);
        $this->assertSame('getOrUpdateTestPost', $post['x-appwrite']['method']);
        $this->assertSame('path', $get['parameters'][0]['in']);
        $this->assertSame('query', $get['parameters'][1]['in']);
        $this->assertArrayNotHasKey('requestBody', $get);
        $this->assertSame('path', $post['parameters'][0]['in']);
        $this->assertCount(1, $post['parameters']);
        $this->assertArrayHasKey('name', $post['requestBody']['content']['application/json']['schema']['properties']);
    }

    public function testModelReferencesDoNotEmitItemsOnObjectProperties(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('GET', '/v1/tests/team'))
            ->desc('Get test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'getTeamTest',
                description: 'Get test.',
                auth: [],
                responses: [
                    new SDKResponse(
                        code: 200,
                        model: Response::MODEL_TEAM,
                    ),
                ],
            ));

        $models = [
            new Team(),
            new Preferences(),
            new ErrorModel(),
        ];

        $openApi = (new OpenAPI3(new Container(), [], [$route], $models, [], 0, 'console'))->parse();

        $openApiPrefs = $openApi['components']['schemas']['team']['properties']['prefs'];

        $this->assertArrayNotHasKey('items', $openApiPrefs);
        $this->assertArrayNotHasKey('error', $openApi['components']['schemas']);
        $this->assertSame('object', $openApiPrefs['type']);
        $this->assertSame([['$ref' => '#/components/schemas/preferences']], $openApiPrefs['allOf']);

    }

    public function testArrayItemsSchemaInfersTypesFromJsonStringExamples(): void
    {
        $this->assertSame(
            [
                'type' => 'array',
                'items' => [
                    'type' => 'number',
                    'format' => 'double',
                ],
            ],
            $this->format->arrayItemsSchema('[[1,2],[3,4]]')
        );

        $this->assertSame(
            [
                'type' => 'object',
                'additionalProperties' => true,
            ],
            $this->format->arrayItemsSchema('[{"resource":"Database","id":"public"}]')
        );

        $this->assertSame(
            ['type' => 'string'],
            $this->format->arrayItemsSchema('["topt", "email"]')
        );

        $this->assertSame(
            ['type' => 'object'],
            $this->format->arrayItemsSchema('[SHARED_SECRET]')
        );
    }

    public function testMultiTypePropertiesWrapOneOfInAllOf(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('GET', '/v1/tests/user'))
            ->desc('Get test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'getUserTest',
                description: 'Get test.',
                auth: [],
                responses: [
                    new SDKResponse(
                        code: 200,
                        model: Response::MODEL_USER,
                    ),
                ],
            ));

        $models = [
            new User(),
            new AlgoArgon2(),
            new AlgoScrypt(),
            new AlgoScryptModified(),
            new AlgoBcrypt(),
            new AlgoPhpass(),
            new AlgoSha(),
            new AlgoMd5(),
        ];

        $openApi = (new OpenAPI3(new Container(), [], [$route], $models, [], 0, 'console'))->parse();

        $openApiHashOptions = $openApi['components']['schemas']['user']['properties']['hashOptions'];

        $this->assertSame('object', $openApiHashOptions['type']);
        $this->assertArrayNotHasKey('items', $openApiHashOptions);
        $this->assertArrayNotHasKey('oneOf', $openApiHashOptions);
        $this->assertCount(1, $openApiHashOptions['allOf']);
        $this->assertCount(7, $openApiHashOptions['allOf'][0]['oneOf']);
        $this->assertSame(['$ref' => '#/components/schemas/algoArgon2'], $openApiHashOptions['allOf'][0]['oneOf'][0]);

    }

    public function testArraySchemasEmitItems(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $requestRoute = (new Route('POST', '/v1/tests/spatial'))
            ->desc('Create spatial test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'createSpatialTest',
                description: 'Create spatial test.',
                auth: [],
                responses: [],
            ))
            ->param('default', null, new Nullable(new Spatial(Database::VAR_LINESTRING)), 'Default value.', true);

        $modelRoute = (new Route('GET', '/v1/tests/spatial-model'))
            ->desc('Get spatial test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'getSpatialTest',
                description: 'Get spatial test.',
                auth: [],
                responses: [
                    new SDKResponse(
                        code: 200,
                        model: Response::MODEL_ATTRIBUTE_LINE,
                    ),
                ],
            ));

        $openApi = (new OpenAPI3(new Container(), [], [$requestRoute, $modelRoute], [new AttributeLine()], [], 0, 'console'))->parse();

        $openApiRequestDefault = $openApi['paths']['/tests/spatial']['post']['requestBody']['content']['application/json']['schema']['properties']['default'];
        $openApiModelDefault = $openApi['components']['schemas']['attributeLine']['properties']['default'];

        foreach ([$openApiRequestDefault, $openApiModelDefault] as $default) {
            $this->assertSame('array', $default['type']);
            $this->assertSame('array', $default['items']['type']);
            $this->assertSame('number', $default['items']['items']['type']);
            $this->assertSame('double', $default['items']['items']['format']);
        }
    }

    public function testPasswordFormatMarksOnlyExplicitPasswordFields(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('POST', '/v1/tests'))
            ->desc('Create test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'createTest',
                description: 'Create test.',
                auth: [],
                responses: [
                    new SDKResponse(
                        code: 200,
                        model: Response::MODEL_WEBHOOK,
                    ),
                ],
            ))
            ->param('password', '', new PasswordFormat(new Text(256)), 'Password.')
            ->param('nullablePassword', null, new Nullable(new PasswordFormat(new Text(256, 0))), 'Nullable password.', true)
            ->param('name', '', new Text(256), 'Name.');

        $openApi = (new OpenAPI3(new Container(), [], [$route], [new Webhook()], [], 0, 'console'))->parse();

        $openApiProperties = $openApi['paths']['/tests']['post']['requestBody']['content']['application/json']['schema']['properties'];

        $this->assertSame('password', $openApiProperties['password']['format']);
        $this->assertSame('password', $openApiProperties['nullablePassword']['format']);
        $this->assertTrue($openApiProperties['nullablePassword']['x-nullable']);
        $this->assertArrayNotHasKey('format', $openApiProperties['name']);
        $this->assertSame('password', $openApi['components']['schemas']['webhook']['properties']['authPassword']['format']);

    }

    public function testNoContentMethodsKeepProducesMetadata(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('DELETE', '/v1/tests/:testId'))
            ->desc('Delete test')
            ->label('scope', 'tests.write')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'deleteTest',
                description: 'Delete test.',
                auth: [],
                responses: [
                    new SDKResponse(
                        code: 204,
                        model: Response::MODEL_NONE,
                    ),
                ],
            ))
            ->param('testId', '', new Text(256), 'Test ID.');

        $openApi = (new OpenAPI3(new Container(), [], [$route], [new NoneModel()], [], 0, 'console'))->parse();

        $openApiMethod = $openApi['paths']['/tests/{testId}']['delete'];

        $this->assertArrayNotHasKey('content', $openApiMethod['responses']['204']);
        $this->assertSame(['application/json'], $openApiMethod['x-appwrite']['produces']);
    }

    public function testBinaryResponsesEmitResponseContent(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('GET', '/v1/tests/icon'))
            ->desc('Get test icon')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'getTestIcon',
                description: 'Get test icon.',
                auth: [],
                responses: [
                    new SDKResponse(
                        code: 200,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::IMAGE_PNG,
            ));

        $openApi = (new OpenAPI3(new Container(), [], [$route], [new NoneModel()], [], 0, 'console'))->parse();

        $openApiMethod = $openApi['paths']['/tests/icon']['get'];

        $this->assertSame(
            ['type' => 'string', 'format' => 'binary'],
            $openApiMethod['responses']['200']['content']['image/png']['schema']
        );
        $this->assertArrayNotHasKey('produces', $openApiMethod['x-appwrite']);
    }

    public function testAdditionalParametersAreIncludedInRequestBody(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('POST', '/v1/tests/graphql'))
            ->desc('GraphQL test endpoint')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'queryTest',
                description: 'GraphQL test endpoint.',
                auth: [],
                responses: [],
                additionalParameters: [
                    'query' => [
                        'default' => [],
                        'validator' => new JSON(),
                        'description' => 'The query or queries to execute.',
                        'optional' => false,
                    ],
                ],
            ));

        $openApi = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();

        $openApiQuery = $openApi['paths']['/tests/graphql']['post']['requestBody']['content']['application/json']['schema']['properties']['query'];

        $this->assertSame('object', $openApiQuery['type']);
    }

    public function testJsonModelRulesKeepAdditionalPropertiesAndSkipNullable(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('GET', '/v1/tests/provider'))
            ->desc('Get test provider')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'getTestProvider',
                description: 'Get test provider.',
                auth: [],
                responses: [
                    new SDKResponse(
                        code: 200,
                        model: Response::MODEL_PROVIDER,
                    ),
                ],
            ));

        $models = [
            new Provider(),
            new ErrorModel(),
        ];

        $openApi = (new OpenAPI3(new Container(), [], [$route], $models, [], 0, 'console'))->parse();

        $openApiOptions = $openApi['components']['schemas']['provider']['properties']['options'];

        $this->assertTrue($openApiOptions['additionalProperties']);
        $this->assertArrayNotHasKey('nullable', $openApiOptions);
    }
}

class TestOpenAPI3 extends OpenAPI3
{
    public function __construct(array $keys = [])
    {
        parent::__construct(new Container(), [], [], [], $keys, 0, 'console');
    }

    public function exposeBuildBaseStructure(): array
    {
        return $this->buildBaseStructure();
    }

    public function exposeBuildModelProperty(string $name, array $rule): array
    {
        return $this->buildModelProperty($name, $rule);
    }

    /**
     * @return array{schema: array, consumes: string|null}
     */
    public function exposeBuildParameterNode(string $name, array $param, Method $sdk): array
    {
        $result = $this->buildParameterNode($name, $param, $sdk);
        return [
            'schema' => $result['node']['schema'],
            'consumes' => $result['consumes'],
        ];
    }

    public function exposeProcessResponses(Method $sdk, string $produces, array &$temp, array &$usedModels): void
    {
        $this->processResponses($sdk, $produces, $temp, $usedModels);
    }

    public function exposeProcessSecurity(Method $sdk, array &$temp): void
    {
        $this->processSecurity($sdk, $temp);
    }

    public function exposeBuildRequest(array &$methodTemp, array $parameterDataList, string $method, string $consumes): void
    {
        $this->buildRequest($methodTemp, $parameterDataList, $method, $consumes);
    }
}

final class OpenAPI3Test extends TestCase
{
    private TestOpenAPI3 $openApi;

    protected function setUp(): void
    {
        parent::setUp();
        Method::$processed = [];
        Method::$errors = [];

        $this->openApi = new TestOpenAPI3();
    }

    public function testBuildBaseStructureContainsExpectedKeys(): void
    {
        $structure = $this->openApi->exposeBuildBaseStructure();

        $this->assertArrayHasKey('openapi', $structure);
        $this->assertSame('3.0.0', $structure['openapi']);
        $this->assertArrayHasKey('info', $structure);
        $this->assertArrayHasKey('paths', $structure);
        $this->assertArrayHasKey('tags', $structure);
        $this->assertArrayHasKey('components', $structure);
        $this->assertArrayHasKey('externalDocs', $structure);
        $this->assertArrayHasKey('schemas', $structure['components']);
        $this->assertArrayHasKey('securitySchemes', $structure['components']);
    }

    public function testBuildBaseStructureDemoInjection(): void
    {
        $structure = $this->openApi->exposeBuildBaseStructure();

        $this->assertEmpty($structure['components']['securitySchemes']);
    }

    public function testBuildModelPropertyStringType(): void
    {
        $property = $this->openApi->exposeBuildModelProperty('name', [
            'type' => 'string',
            'description' => 'The name.',
        ]);

        $this->assertSame('string', $property['type']);
        $this->assertSame('The name.', $property['description']);
        $this->assertArrayNotHasKey('items', $property);
        $this->assertArrayNotHasKey('enum', $property);
    }

    public function testBuildModelPropertyIntegerType(): void
    {
        $property = $this->openApi->exposeBuildModelProperty('count', [
            'type' => 'integer',
            'format' => 'int64',
            'description' => 'The count.',
        ]);

        $this->assertSame('integer', $property['type']);
        $this->assertSame('int64', $property['format']);
    }

    public function testBuildModelPropertyBooleanType(): void
    {
        $property = $this->openApi->exposeBuildModelProperty('enabled', [
            'type' => 'boolean',
            'description' => 'Is enabled.',
        ]);

        $this->assertSame('boolean', $property['type']);
    }

    public function testBuildModelPropertyArrayType(): void
    {
        $property = $this->openApi->exposeBuildModelProperty('tags', [
            'type' => 'array',
            'description' => 'List of tags.',
            'example' => ['a', 'b'],
        ]);

        $this->assertSame('array', $property['type']);
        $this->assertArrayHasKey('items', $property);
    }

    public function testBuildModelPropertyEnumType(): void
    {
        $property = $this->openApi->exposeBuildModelProperty('status', [
            'type' => 'enum',
            'description' => 'Status.',
            'enum' => ['active', 'inactive', 'pending'],
            'enumSDKName' => 'StatusType',
        ]);

        $this->assertSame('string', $property['type']);
        $this->assertSame(['active', 'inactive', 'pending'], $property['enum']);
        $this->assertSame('StatusType', $property['x-enum-name']);
    }

    public function testBuildModelPropertyJsonType(): void
    {
        $property = $this->openApi->exposeBuildModelProperty('options', [
            'type' => 'json',
            'description' => 'JSON options.',
        ]);

        $this->assertSame('object', $property['type']);
        $this->assertTrue($property['additionalProperties']);
        $this->assertArrayNotHasKey('nullable', $property);
    }

    public function testBuildModelPropertyWithArrayRef(): void
    {
        $property = $this->openApi->exposeBuildModelProperty('items', [
            'type' => 'Document',
            'array' => true,
            'description' => 'List of documents.',
        ]);

        $this->assertSame('array', $property['type']);
        $this->assertSame(['$ref' => '#/components/schemas/Document'], $property['items']);
    }

    public function testBuildModelPropertyWithObjectRef(): void
    {
        $property = $this->openApi->exposeBuildModelProperty('prefs', [
            'type' => 'Preferences',
            'array' => false,
            'description' => 'User preferences.',
        ]);

        $this->assertSame('object', $property['type']);
        $this->assertCount(1, $property['allOf']);
        $this->assertSame(
            ['$ref' => '#/components/schemas/Preferences'],
            $property['allOf'][0]
        );
    }

    public function testBuildModelPropertyNullable(): void
    {
        $this->markTestSkipped('Nullable is applied in buildModelSchema, not buildModelProperty');
    }

    public function testBuildParameterNodeTextValidator(): void
    {
        $route = (new Route('POST', '/v1/tests'))
            ->param('name', '', new Text(128), 'Name.');

        $result = $this->openApi->exposeBuildParameterNode(
            'name',
            $route->getParams()['name'],
            new Method(
                namespace: 'test',
                group: null,
                name: 'createTest',
                description: 'Create test.',
                auth: [],
                responses: [],
            ),
        );

        $this->assertSame('string', $result['schema']['type']);
        $this->assertNull($result['consumes']);
    }

    public function testBuildParameterNodeBooleanValidator(): void
    {
        $route = (new Route('POST', '/v1/tests'))
            ->param('enabled', false, new \Utopia\Validator\Boolean(), 'Enabled.', true);

        $result = $this->openApi->exposeBuildParameterNode(
            'enabled',
            $route->getParams()['enabled'],
            new Method(
                namespace: 'test',
                group: null,
                name: 'createTest',
                description: 'Create test.',
                auth: [],
                responses: [],
            ),
        );

        $this->assertSame('boolean', $result['schema']['type']);
        $this->assertFalse($result['schema']['x-example']);
    }

    public function testBuildParameterNodeIntegerValidator(): void
    {
        $route = (new Route('POST', '/v1/tests'))
            ->param('count', 0, new \Utopia\Validator\Integer(), 'Count.');

        $result = $this->openApi->exposeBuildParameterNode(
            'count',
            $route->getParams()['count'],
            new Method(
                namespace: 'test',
                group: null,
                name: 'createTest',
                description: 'Create test.',
                auth: [],
                responses: [],
            ),
        );

        $this->assertSame('integer', $result['schema']['type']);
    }

    public function testBuildParameterNodeCustomId(): void
    {
        $route = (new Route('POST', '/v1/tests'))
            ->param('userId', '', new CustomId(), 'User ID.');

        $result = $this->openApi->exposeBuildParameterNode(
            'userId',
            $route->getParams()['userId'],
            new Method(
                namespace: 'test',
                group: null,
                name: 'createTest',
                description: 'Create test.',
                auth: [],
                responses: [],
            ),
        );

        $this->assertSame('string', $result['schema']['type']);
        $this->assertSame(
            ['idGenerator' => 'ID.unique'],
            $result['schema']['x-appwrite']
        );
    }

    public function testBuildParameterNodeFileValidator(): void
    {
        $route = (new Route('POST', '/v1/tests'))
            ->param('file', '', new \Appwrite\Utopia\Request\Validator\File(), 'File.');

        $result = $this->openApi->exposeBuildParameterNode(
            'file',
            $route->getParams()['file'],
            new Method(
                namespace: 'test',
                group: null,
                name: 'createTest',
                description: 'Create test.',
                auth: [],
                responses: [],
            ),
        );

        $this->assertSame('string', $result['schema']['type']);
        $this->assertSame('binary', $result['schema']['format']);
        $this->assertSame('multipart/form-data', $result['consumes']);
    }

    public function testBuildRequestSplitsParamsByLocation(): void
    {
        $methodTemp = [
            'parameters' => [],
        ];

        $parameterDataList = [
            [
                'name' => 'testId',
                'config' => ['required' => true, 'nullable' => false, 'emitDefault' => false],
                'node' => [
                    'name' => 'testId',
                    'description' => 'Test ID.',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ],
                'path' => true,
            ],
            [
                'name' => 'limit',
                'config' => ['required' => false, 'nullable' => false, 'emitDefault' => true],
                'node' => [
                    'name' => 'limit',
                    'description' => 'Limit.',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'default' => 25],
                ],
                'path' => false,
            ],
        ];

        $this->openApi->exposeBuildRequest($methodTemp, $parameterDataList, 'GET', 'application/json');

        $this->assertArrayNotHasKey('requestBody', $methodTemp);
        $this->assertCount(2, $methodTemp['parameters']);
        $this->assertSame('path', $methodTemp['parameters'][0]['in']);
        $this->assertSame('query', $methodTemp['parameters'][1]['in']);
    }

    public function testBuildRequestBodyParamsInPost(): void
    {
        $methodTemp = [
            'parameters' => [],
        ];

        $parameterDataList = [
            [
                'name' => 'name',
                'config' => ['required' => true, 'nullable' => false, 'emitDefault' => false],
                'node' => [
                    'name' => 'name',
                    'description' => 'Name.',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ],
                'path' => false,
            ],
        ];

        $this->openApi->exposeBuildRequest($methodTemp, $parameterDataList, 'POST', 'application/json');

        $this->assertArrayHasKey('requestBody', $methodTemp);
        $this->assertSame(
            'name',
            $methodTemp['requestBody']['content']['application/json']['schema']['required'][0]
        );
    }
}
