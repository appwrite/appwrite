<?php

namespace Tests\Unit\SDK\Specification;

use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Format;
use Appwrite\SDK\Specification\Format\OpenAPI3;
use Appwrite\SDK\Specification\Format\Swagger2;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\HealthStatus;
use Appwrite\Utopia\Response\Model\PlatformAndroid;
use Appwrite\Utopia\Response\Model\PlatformApple;
use Appwrite\Utopia\Response\Model\PlatformLinux;
use Appwrite\Utopia\Response\Model\PlatformList;
use Appwrite\Utopia\Response\Model\PlatformWeb;
use Appwrite\Utopia\Response\Model\PlatformWindows;
use Appwrite\Utopia\Response\Model\Webhook;
use PHPUnit\Framework\TestCase;
use Utopia\DI\Container;
use Utopia\Http\Route;
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
        $swagger = (new Swagger2(new Container(), [], [$route], [new Webhook()], [], 0, 'console'))->parse();

        $openApiProperties = $openApi['paths']['/tests']['post']['requestBody']['content']['application/json']['schema']['properties'];
        $swaggerProperties = $swagger['paths']['/tests']['post']['parameters'][0]['schema']['properties'];

        $this->assertSame('password', $openApiProperties['password']['format']);
        $this->assertSame('password', $openApiProperties['nullablePassword']['format']);
        $this->assertTrue($openApiProperties['nullablePassword']['x-nullable']);
        $this->assertArrayNotHasKey('format', $openApiProperties['name']);
        $this->assertSame('password', $openApi['components']['schemas']['webhook']['properties']['authPassword']['format']);

        $this->assertSame('password', $swaggerProperties['password']['format']);
        $this->assertSame('password', $swaggerProperties['nullablePassword']['format']);
        $this->assertTrue($swaggerProperties['nullablePassword']['x-nullable']);
        $this->assertArrayNotHasKey('format', $swaggerProperties['name']);
        $this->assertSame('password', $swagger['definitions']['webhook']['properties']['authPassword']['format']);
    }
}
