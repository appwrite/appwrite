<?php

namespace Tests\Unit\SDK\Specification;

use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Format\OpenAPI3;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Preferences;
use Appwrite\Utopia\Response\Model\Team;
use PHPUnit\Framework\TestCase;
use Utopia\DI\Container;
use Utopia\Http\Route;
use Utopia\OpenAPI\Model\ArraySchema;
use Utopia\OpenAPI\Model\ObjectSchema;
use Utopia\OpenAPI\Parser;
use Utopia\OpenAPI\Version;
use Utopia\Platform\Enum;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean as BooleanValidator;
use Utopia\Validator\Integer as IntegerValidator;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/**
 * The generated specs are build artifacts, so nothing checks them until an SDK
 * is generated and something downstream breaks. Parse the formatter's output
 * with a real OpenAPI parser instead, so a formatter change that produces an
 * invalid document fails here rather than in a consumer.
 */
class OpenAPI3ValidityTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $spec
     */
    private function parseSpec(array $spec): \Utopia\OpenAPI\Specification
    {
        return Parser::parse($spec, Version::V3_0);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSpec(): array
    {
        Method::$processed = [];
        Method::$errors = [];

        $list = (new Route('GET', '/v1/tests'))
            ->desc('List tests')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'listTests',
                description: 'List tests.',
                auth: [],
                responses: [new SDKResponse(code: Response::STATUS_CODE_OK, model: Response::MODEL_TEAM)],
            ))
            ->param('search', '', new Text(256), 'Search term.', true)
            ->param('limit', 25, new IntegerValidator(), 'Result limit.', true)
            ->param('verbose', false, new BooleanValidator(), 'Verbose output.', true)
            ->param('cursor', null, new Nullable(new Text(256)), 'Cursor.', true)
            ->param('order', 'asc', new WhiteList(['asc', 'desc']), 'Sort order.', true, enum: new Enum(name: 'TestOrder'))
            ->param('fields', [], new ArrayList(new WhiteList(['id', 'name'], true), 10), 'Fields.', true, enum: new Enum(name: 'TestField'));

        $create = (new Route('POST', '/v1/tests'))
            ->desc('Create test')
            ->label('sdk', new Method(
                namespace: 'test',
                group: null,
                name: 'createTest',
                description: 'Create test.',
                auth: [],
                responses: [new SDKResponse(code: Response::STATUS_CODE_CREATED, model: Response::MODEL_TEAM)],
            ))
            ->param('name', '', new Text(128), 'Name.')
            ->param('kind', 'basic', new WhiteList(['basic', 'advanced']), 'Kind.', true, enum: new Enum(name: 'TestKind'));

        $models = [new Team(), new Preferences()];

        return (new OpenAPI3(new Container(), [], [$list, $create], $models, [], 0, 'console'))->parse();
    }

    public function testGeneratedDocumentParsesAsOpenApi30(): void
    {
        $spec = $this->parseSpec($this->buildSpec());

        $this->assertSame(Version::V3_0, $spec->version);
        $this->assertArrayHasKey('/tests', $spec->paths);
        $this->assertArrayHasKey('get', $spec->paths['/tests']->operations);
        $this->assertArrayHasKey('post', $spec->paths['/tests']->operations);
    }

    public function testEnumParametersSurviveThroughTheParser(): void
    {
        $spec = $this->parseSpec($this->buildSpec());
        $operation = $spec->paths['/tests']->operations['get'];

        $byName = [];
        foreach ($operation->parameters as $parameter) {
            $byName[$parameter->name] = $parameter;
        }

        $this->assertSame(['asc', 'desc'], $byName['order']->schema->enum);
        $this->assertSame('TestOrder', $byName['order']->schema->extensions['x-enum-name']);

        $fields = $byName['fields']->schema;
        $this->assertInstanceOf(ArraySchema::class, $fields);
        $this->assertSame(['id', 'name'], $fields->items->enum);
        $this->assertSame('TestField', $fields->items->extensions['x-enum-name']);
    }

    public function testAppwriteExtensionsSurviveThroughTheParser(): void
    {
        $spec = $this->parseSpec($this->buildSpec());
        $operation = $spec->paths['/tests']->operations['get'];

        // Codegen depends on these, so a formatter change that drops them
        // should fail here and not three repositories downstream.
        $this->assertArrayHasKey('x-appwrite', $operation->extensions);
        $this->assertArrayHasKey('rate-limit', $operation->extensions['x-appwrite']);
        $this->assertArrayHasKey('scope', $operation->extensions['x-appwrite']);
        $this->assertSame('testListTests', $operation->id);
    }

    public function testResponseModelsBecomeComponentSchemas(): void
    {
        $spec = $this->parseSpec($this->buildSpec());

        $this->assertArrayHasKey('team', $spec->schemas);
        $this->assertInstanceOf(ObjectSchema::class, $spec->schemas['team']);
        $this->assertArrayHasKey('$id', $spec->schemas['team']->properties);
    }
}
