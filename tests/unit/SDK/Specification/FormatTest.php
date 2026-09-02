<?php

namespace Tests\Unit\SDK\Specification;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Format;
use Appwrite\SDK\Specification\Format\OpenAPI3;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\VcsRepositories;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\AlgoArgon2;
use Appwrite\Utopia\Response\Model\AlgoBcrypt;
use Appwrite\Utopia\Response\Model\AlgoMd5;
use Appwrite\Utopia\Response\Model\AlgoPhpass;
use Appwrite\Utopia\Response\Model\AlgoScrypt;
use Appwrite\Utopia\Response\Model\AlgoScryptModified;
use Appwrite\Utopia\Response\Model\AlgoSha;
use Appwrite\Utopia\Response\Model as ResponseModel;
use Appwrite\Utopia\Response\Model\AttributeLine;
use Appwrite\Utopia\Response\Model\Error as ErrorModel;
use Appwrite\Utopia\Response\Model\ErrorDev;
use Appwrite\Utopia\Response\Model\FrameworkAdapter;
use Appwrite\Utopia\Response\Model\HealthStatus;
use Appwrite\Utopia\Response\Model\Metric;
use Appwrite\Utopia\Response\Model\Migration;
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
use Appwrite\Utopia\Response\Model\TemplateFramework;
use Appwrite\Utopia\Response\Model\TemplateSite;
use Appwrite\Utopia\Response\Model\TemplateVariable;
use Appwrite\Utopia\Response\Model\UsageDataPoint;
use Appwrite\Utopia\Response\Model\UsageProject;
use Appwrite\Utopia\Response\Model\User;
use Appwrite\Utopia\Response\Model\Webhook;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Spatial;
use Utopia\DI\Container;
use Utopia\Http\Route;
use Utopia\Platform\Enum;
use Utopia\Validator\AnyOf;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean as BooleanValidator;
use Utopia\Validator\Domain;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\HexColor;
use Utopia\Validator\Integer as IntegerValidator;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

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

class MixedValidator extends \Utopia\Validator
{
    public function getDescription(): string
    {
        return 'Mixed value';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function isValid($value): bool
    {
        return true;
    }

    public function getType(): string
    {
        return self::TYPE_MIXED;
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

    public function testUnionWithAFreeStringBranchEmitsAnyOf(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('GET', '/v1/tests'))
            ->desc('List tests')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'listTests',
                description: 'List tests.',
                auth: [],
                responses: [],
            ))
            ->param('metrics', [], new AnyOf([
                new ArrayList(new WhiteList(['alpha', 'beta'], true), 10),
                new ArrayList(new Text(255), 10),
            ]), 'Metric names.', false, enum: new Enum(name: 'TestMetric'));

        $spec = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();

        $this->assertSame([
            'type' => 'string',
            'anyOf' => [
                [
                    'title' => 'TestMetric',
                    'type' => 'string',
                    'oneOf' => [
                        ['type' => 'string', 'enum' => ['alpha'], 'title' => 'alpha'],
                        ['type' => 'string', 'enum' => ['beta'], 'title' => 'beta'],
                    ],
                ],
                ['type' => 'string'],
            ],
        ], $spec['paths']['/tests']['get']['parameters'][0]['schema']['items']);
    }

    public function testClosedEnumEmitsAnnotatedBranchesInRequestBody(): void
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
            ->param('kind', 'basic', new WhiteList(['basic', 'advanced']), 'Test kind.', enum: new Enum(
                name: 'TestKind',
                map: ['basic' => 'Basic', 'advanced' => 'Advanced'],
            ));

        $spec = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();
        $kind = $spec['paths']['/tests']['post']['requestBody']['content']['application/json']['schema']['properties']['kind'];

        $this->assertSame('TestKind', $kind['title']);
        $this->assertSame([
            ['type' => 'string', 'enum' => ['basic'], 'title' => 'Basic'],
            ['type' => 'string', 'enum' => ['advanced'], 'title' => 'Advanced'],
        ], $kind['oneOf']);
        $this->assertSame('string', $kind['type']);
        $this->assertArrayNotHasKey('x-enum-name', $kind);
        $this->assertArrayNotHasKey('x-enum-keys', $kind);
    }

    public function testEnumNameMustNotOverlapServiceName(): void
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
            ->param('kind', 'basic', new WhiteList(['basic', 'advanced']), 'Test kind.', enum: new Enum());

        $format = new OpenAPI3(
            new Container(),
            [['name' => 'Kind', 'description' => 'Test kinds.']],
            [$route],
            [],
            [],
            0,
            'console',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Spec service name 'Kind' must not overlap enum 'kind'.");

        $format->parse();
    }

    public function testResponseModelEnumEmitsAnnotatedBranches(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('GET', '/v1/health'))
            ->desc('Get health')
            ->label('sdk', new Method(
                namespace: 'health',
                group: null,
                name: 'get',
                description: 'Get health.',
                auth: [],
                responses: [new SDKResponse(code: Response::STATUS_CODE_OK, model: Response::MODEL_HEALTH_STATUS)],
            ));

        $spec = (new OpenAPI3(new Container(), [], [$route], [new HealthStatus()], [], 0, 'console'))->parse();
        $status = $spec['components']['schemas']['healthStatus']['properties']['status'];

        $this->assertSame('HealthCheckStatus', $status['title']);
        $this->assertNotEmpty($status['oneOf']);
        foreach ($status['oneOf'] as $branch) {
            $this->assertSame('string', $branch['type']);
            $this->assertCount(1, $branch['enum']);
            $this->assertSame($branch['enum'][0], $branch['title']);
        }
        $this->assertArrayNotHasKey('x-enum-name', $status);
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

        $userId = $spec['paths']['/tests']['post']['requestBody']['content']['application/json']['schema']['properties']['userId'];

        $this->assertSame(['idGenerator' => 'ID.unique'], $userId['x-appwrite']);
        $this->assertSame('<USER_ID>', $userId['example']);
        $this->assertArrayNotHasKey('x-example', $userId);
    }

    public function testOpenApiExamplesUseNativeSchemaTypes(): void
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
            ->param('metadata', [], new Assoc(), 'Metadata.', example: '{"enabled":true}')
            ->param('labels', [], new ArrayList(new Text(16)), 'Labels.', example: '["one","two"]')
            ->param('singleLabel', [], new ArrayList(new Text(16)), 'Single label.', example: 'one')
            ->param('count', 0, new Range(0, 100), 'Count.', example: '42')
            ->param('ratio', 0, new Range(0, 10, Range::TYPE_FLOAT), 'Ratio.', example: '2.5')
            ->param('enabled', false, new BooleanValidator(true), 'Enabled.', example: 'true')
            ->param('text', '', new Text(64), 'Text.', example: '["one","two"]');

        $spec = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();
        $properties = $spec['paths']['/tests']['post']['requestBody']['content']['application/json']['schema']['properties'];

        $this->assertEquals((object) ['enabled' => true], $properties['metadata']['example']);
        $this->assertSame(['one', 'two'], $properties['labels']['example']);
        $this->assertSame(['one'], $properties['singleLabel']['example']);
        $this->assertSame(42, $properties['count']['example']);
        $this->assertEqualsWithDelta(2.5, $properties['ratio']['example'], PHP_FLOAT_EPSILON);
        $this->assertTrue($properties['enabled']['example']);
        $this->assertSame('["one","two"]', $properties['text']['example']);
    }

    public function testArrayListItemTypesAreValidOpenApiTypes(): void
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
            ->param('percents', [], new ArrayList(new FloatValidator()), 'Percents.', optional: true)
            ->param('counts', [], new ArrayList(new IntegerValidator()), 'Counts.', optional: true)
            ->param('labels', [], new ArrayList(new Text(16)), 'Labels.', optional: true)
            ->param('values', [], new ArrayList(new MixedValidator()), 'Values.', optional: true);

        $spec = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();
        $properties = $spec['paths']['/tests']['post']['requestBody']['content']['application/json']['schema']['properties'];

        $this->assertSame(['type' => 'number', 'format' => 'double'], $properties['percents']['items']);
        $this->assertSame(['type' => 'integer'], $properties['counts']['items']);
        $this->assertSame(['type' => 'string'], $properties['labels']['items']);
        $this->assertInstanceOf(\stdClass::class, $properties['values']['items']);
        $this->assertSame('{}', json_encode($properties['values']['items']));
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

        $this->assertSame('testGetOrUpdateTest', $get['operationId']);
        $this->assertSame('testGetOrUpdateTestPost', $post['operationId']);
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

    /**
     * A route only matches when every path segment is present, so a path
     * parameter is always supplied no matter what the PHP param declares.
     * Marking one optional produced a Go SDK that does not compile
     * (`undefined: SessionId`) and a Python SDK that requested
     * `/account/sessions/None`.
     */
    public function testObjectModelReferencesWithoutExamplesOmitExample(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $parent = new class () extends ResponseModel {
            public function __construct()
            {
                $this->addRule('child', [
                    'type' => 'childWithoutExample',
                    'description' => 'Nested child.',
                    'default' => null,
                ]);
            }

            public function getName(): string
            {
                return 'Parent without example';
            }

            public function getType(): string
            {
                return 'parentWithoutExample';
            }
        };

        $child = new class () extends ResponseModel {
            public function getName(): string
            {
                return 'Child without example';
            }

            public function getType(): string
            {
                return 'childWithoutExample';
            }
        };

        $route = (new Route('GET', '/v1/tests/parent'))
            ->desc('Get parent')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'getParent',
                description: 'Get parent.',
                auth: [],
                responses: [
                    new SDKResponse(
                        code: 200,
                        model: 'parentWithoutExample',
                    ),
                ],
            ));

        $openApi = (new OpenAPI3(new Container(), [], [$route], [$parent, $child], [], 0, 'console'))->parse();
        $property = $openApi['components']['schemas']['parentWithoutExample']['properties']['child'];

        $this->assertSame('object', $property['type']);
        $this->assertArrayNotHasKey('example', $property);
    }

    public function testExplicitEmptyArrayExampleIsPreserved(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('GET', '/v1/tests/error'))
            ->desc('Get error')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'getError',
                description: 'Get error.',
                auth: [],
                responses: [
                    new SDKResponse(
                        code: 500,
                        model: Response::MODEL_ERROR_DEV,
                    ),
                ],
            ));

        $openApi = (new OpenAPI3(new Container(), [], [$route], [new ErrorDev()], [], 0, 'console'))->parse();
        $trace = $openApi['components']['schemas']['errorDev']['properties']['trace'];

        $this->assertSame('array', $trace['type']);
        $this->assertSame([], $trace['example']);
    }

    public function testOptionalPathParameterIsEmittedAsRequired(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('GET', '/v1/tests/:sessionId'))
            ->desc('Get test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'getPathTest',
                description: 'Get test.',
                auth: [],
                responses: [
                    new SDKResponse(code: 200, model: Response::MODEL_NONE),
                ],
            ))
            ->param('sessionId', 'current', new Text(256), 'Session ID.', true)
            ->param('filter', '', new Text(256), 'Optional query filter.', true);

        $openApi = (new OpenAPI3(new Container(), [], [$route], [new NoneModel()], [], 0, 'console'))->parse();

        $parameters = [];
        foreach ($openApi['paths']['/tests/{sessionId}']['get']['parameters'] as $parameter) {
            $parameters[$parameter['name']] = $parameter;
        }

        $this->assertSame('path', $parameters['sessionId']['in']);
        $this->assertTrue($parameters['sessionId']['required']);

        // An optional query parameter is untouched — only the path is forced.
        $this->assertSame('query', $parameters['filter']['in']);
        $this->assertFalse($parameters['filter']['required']);
    }

    /**
     * The project usage handler writes the text embedding metrics as four
     * per-period lists plus four scalar totals. A typed SDK generated from a
     * schema that calls all eight a single metric object rejects every valid
     * response, so pin the emitted schema rather than the rule table.
     */
    public function testUsageProjectEmbeddingsTextSchema(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('GET', '/v1/tests/usage'))
            ->desc('Get test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'getUsageTest',
                description: 'Get test.',
                auth: [],
                responses: [
                    new SDKResponse(
                        code: 200,
                        model: Response::MODEL_USAGE_PROJECT,
                    ),
                ],
            ));

        $models = [
            new UsageProject(),
            new Metric(),
            new ErrorModel(),
        ];

        $openApi = (new OpenAPI3(new Container(), [], [$route], $models, [], 0, 'console'))->parse();

        $properties = $openApi['components']['schemas']['usageProject']['properties'];

        foreach (['embeddingsText', 'embeddingsTextTokens', 'embeddingsTextDuration', 'embeddingsTextErrors'] as $key) {
            $this->assertSame('array', $properties[$key]['type'], $key);
            $this->assertSame(['$ref' => '#/components/schemas/metric'], $properties[$key]['items'], $key);
            $this->assertArrayNotHasKey('allOf', $properties[$key], $key);
        }

        foreach (['embeddingsTextTotal', 'embeddingsTextTokensTotal', 'embeddingsTextDurationTotal', 'embeddingsTextErrorsTotal'] as $key) {
            $this->assertSame('integer', $properties[$key]['type'], $key);
            $this->assertArrayNotHasKey('allOf', $properties[$key], $key);
            $this->assertArrayNotHasKey('items', $properties[$key], $key);
        }
    }

    public function testJsonArrayModelExamplesUseArraySchemas(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = (new Route('GET', '/v1/tests/migration'))
            ->desc('Get migration')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'getMigration',
                description: 'Get migration.',
                auth: [],
                responses: [
                    new SDKResponse(
                        code: 200,
                        model: Response::MODEL_MIGRATION,
                    ),
                ],
            ));

        $openApi = (new OpenAPI3(new Container(), [], [$route], [new Migration()], [], 0, 'console'))->parse();
        $resourceData = $openApi['components']['schemas']['migration']['properties']['resourceData'];

        $this->assertSame('array', $resourceData['type']);
        $this->assertSame(['type' => 'object'], $resourceData['items']);
        $this->assertSame([
            [
                'resource' => 'Database',
                'id' => 'public',
                'status' => 'SUCCESS',
                'message' => '',
            ],
        ], $resourceData['example']);
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

        $this->assertSame([[1, 2], [3, 4], [5, 6]], $openApiRequestDefault['example']);
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
        $this->assertSame('password', $openApiProperties['password']['example']);
        $this->assertArrayNotHasKey('x-example', $openApiProperties['password']);
        $this->assertSame('password', $openApiProperties['nullablePassword']['format']);
        $this->assertTrue($openApiProperties['nullablePassword']['nullable']);
        $this->assertArrayNotHasKey('format', $openApiProperties['name']);

        $authPassword = $openApi['components']['schemas']['webhook']['properties']['authPassword'];
        $this->assertSame('password', $authPassword['format']);
        $this->assertSame('webhook-password', $authPassword['example']);
        $this->assertArrayNotHasKey('x-example', $authPassword);

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

    public function testJsonAndNullableModelRulesEmitExpectedSchemas(): void
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
            new FrameworkAdapter(),
            new TemplateFramework(),
            new TemplateSite(),
            new TemplateVariable(),
            new UsageDataPoint(),
            new ErrorModel(),
        ];
        $routes = [$route];

        foreach ([
            Response::MODEL_FRAMEWORK_ADAPTER,
            Response::MODEL_TEMPLATE_FRAMEWORK,
            Response::MODEL_TEMPLATE_SITE,
            Response::MODEL_USAGE_DATA_POINT,
        ] as $model) {
            $routes[] = (new Route('GET', '/v1/tests/' . $model))
                ->desc('Get test response model')
                ->label('sdk', new Method(
                    namespace: 'test',
                    group: null,
                    name: 'get' . \ucfirst($model),
                    description: 'Get test response model.',
                    auth: [],
                    responses: [
                        new SDKResponse(
                            code: 200,
                            model: $model,
                        ),
                    ],
                ));
        }

        $openApi = (new OpenAPI3(new Container(), [], $routes, $models, [], 0, 'console'))->parse();

        $openApiOptions = $openApi['components']['schemas']['provider']['properties']['options'];

        $this->assertTrue($openApiOptions['additionalProperties']);
        $this->assertArrayNotHasKey('nullable', $openApiOptions);

        foreach ([
            Response::MODEL_FRAMEWORK_ADAPTER => 'fallbackFile',
            Response::MODEL_TEMPLATE_FRAMEWORK => 'fallbackFile',
            Response::MODEL_TEMPLATE_SITE => 'demoUrl',
            Response::MODEL_USAGE_DATA_POINT => 'time',
        ] as $model => $property) {
            $schema = $openApi['components']['schemas'][$model];

            $this->assertTrue($schema['properties'][$property]['nullable']);
            $this->assertNotContains($property, $schema['required']);
        }
    }

    public function testQueriesSubclassesEmitArrayOfStrings(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        // VcsRepositories extends Queries directly rather than Queries\Base, and a
        // deeper subclass proves arbitrary inheritance depth is normalised too.
        $deepSubclass = new class () extends VcsRepositories {};

        $route = (new Route('GET', '/v1/tests/queries'))
            ->desc('List tests')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'listTests',
                description: 'List tests.',
                auth: [],
                responses: [],
            ))
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Queries.', true)
            ->param('repositoryQueries', [], new VcsRepositories(), 'Repository queries.', true)
            ->param('deepQueries', [], $deepSubclass, 'Deeply nested queries.', true);

        $openApi = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();

        $parameters = $openApi['paths']['/tests/queries']['get']['parameters'];
        $schemas = \array_column($parameters, 'schema', 'name');

        $this->assertCount(3, $schemas);

        foreach (['queries', 'repositoryQueries', 'deepQueries'] as $name) {
            $this->assertSame('array', $schemas[$name]['type'], "{$name} must serialise as an array");
            $this->assertSame(['type' => 'string'], $schemas[$name]['items'], "{$name} must hold query strings");
        }
    }

    public function testZeroIsKeptAsADeclaredExample(): void
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
            ->param('min', 0, new Range(0, 100), 'Minimum.', example: '0')
            ->param('label', '', new Text(64), 'Label.', example: '0');

        $spec = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();
        $properties = $spec['paths']['/tests']['post']['requestBody']['content']['application/json']['schema']['properties'];

        // "0" is falsy, so a truthiness check silently discards it and falls back
        // to the validator default -- Range::getMin() here, and a <LABEL>
        // placeholder for the string.
        $this->assertSame(0, $properties['min']['example']);
        $this->assertSame('0', $properties['label']['example']);
    }

    public function testValidatorsWithoutAnExampleFallBackToOne(): void
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
            ->param('key', '', new Key(), 'Column key.')
            ->param('newKey', '', new Nullable(new Key()), 'New column key.', true)
            ->param('domain', '', new Domain(), 'Domain name.')
            ->param('background', '', new HexColor(), 'Background colour.');

        $spec = (new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse();
        $properties = $spec['paths']['/tests']['post']['requestBody']['content']['application/json']['schema']['properties'];

        // Without a case of their own these validators fell through to the
        // default arm, which leaves no example at all and renders as an empty
        // literal in every generated SDK example.
        $this->assertSame('<KEY>', $properties['key']['example']);
        $this->assertSame('<NEW_KEY>', $properties['newKey']['example']);
        $this->assertSame('example.com', $properties['domain']['example']);
        $this->assertSame('FFFFFF', $properties['background']['example']);
    }

    public function testExplicitMethodParametersAreTheSdkMethodList(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = $this->createDocumentsRoute(
            createDocument: [
                new Parameter('databaseId', optional: false),
                new Parameter('collectionId', optional: false),
                new Parameter('documentId', optional: false),
                new Parameter('data', optional: false),
                new Parameter('permissions', optional: true),
                new Parameter('transactionId', optional: true),
            ],
            createDocuments: [
                new Parameter('databaseId', optional: false),
                new Parameter('collectionId', optional: false),
                new Parameter('documents', optional: false),
                new Parameter('transactionId', optional: true),
            ],
        );

        $methods = $this->sdkMethods((new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse());

        $this->assertContains('transactionId', $methods['createDocument']);
        $this->assertContains('transactionId', $methods['createDocuments']);
    }

    public function testOmittedMethodParametersAreDroppedFromTheSdkMethodList(): void
    {
        Method::$processed = [];
        Method::$errors = [];

        $route = $this->createDocumentsRoute(
            createDocument: [
                new Parameter('databaseId', optional: false),
                new Parameter('collectionId', optional: false),
                new Parameter('documentId', optional: false),
                new Parameter('data', optional: false),
                new Parameter('permissions', optional: true),
            ],
            createDocuments: [
                new Parameter('databaseId', optional: false),
                new Parameter('collectionId', optional: false),
                new Parameter('documents', optional: false),
            ],
        );

        $methods = $this->sdkMethods((new OpenAPI3(new Container(), [], [$route], [], [], 0, 'console'))->parse());

        $this->assertNotContains('transactionId', $methods['createDocument']);
        $this->assertNotContains('transactionId', $methods['createDocuments']);
    }

    /**
     * @param list<Parameter> $createDocument
     * @param list<Parameter> $createDocuments
     */
    private function createDocumentsRoute(array $createDocument, array $createDocuments): Route
    {
        return (new Route('POST', '/v1/documentsdb/:databaseId/collections/:collectionId/documents'))
            ->desc('Create document')
            ->label('sdk', [
                new Method(
                    namespace: 'documentsDB',
                    group: 'documents',
                    name: 'createDocument',
                    description: 'Create document.',
                    auth: [AuthType::ADMIN],
                    responses: [],
                    parameters: $createDocument,
                ),
                new Method(
                    namespace: 'documentsDB',
                    group: 'documents',
                    name: 'createDocuments',
                    description: 'Create documents.',
                    auth: [AuthType::ADMIN],
                    responses: [],
                    parameters: $createDocuments,
                ),
            ])
            ->param('databaseId', '', new Text(256), 'Database ID.')
            ->param('collectionId', '', new Text(256), 'Collection ID.')
            ->param('documentId', '', new Text(256), 'Document ID.', true)
            ->param('data', [], new JSON(), 'Document data.', true)
            ->param('permissions', null, new Nullable(new Text(256)), 'Permissions.', true)
            ->param('documents', [], new JSON(), 'Documents.', true)
            ->param('transactionId', null, new Nullable(new Text(256)), 'Transaction ID.', true);
    }

    /**
     * @return array<string, list<string>>
     */
    private function sdkMethods(array $openApi): array
    {
        $path = '/documentsdb/{databaseId}/collections/{collectionId}/documents';
        $this->assertArrayHasKey($path, $openApi['paths']);

        $methods = [];
        foreach ($openApi['paths'][$path]['post']['x-appwrite']['methods'] as $method) {
            $methods[$method['name']] = $method['parameters'];
        }

        $this->assertArrayHasKey('createDocument', $methods);
        $this->assertArrayHasKey('createDocuments', $methods);

        return $methods;
    }
}
