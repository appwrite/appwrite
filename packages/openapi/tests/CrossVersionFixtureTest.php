<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\OpenAPI\Model\HttpMethod;
use Utopia\OpenAPI\Model\IntegerSchema;
use Utopia\OpenAPI\Model\ObjectSchema;
use Utopia\OpenAPI\Model\Operation;
use Utopia\OpenAPI\Model\ParameterLocation;
use Utopia\OpenAPI\Model\ReferenceSchema;
use Utopia\OpenAPI\Model\SecurityRequirement;
use Utopia\OpenAPI\Model\SecurityScheme;
use Utopia\OpenAPI\Model\SecuritySchemeType;
use Utopia\OpenAPI\Model\Server;
use Utopia\OpenAPI\Model\StringSchema;
use Utopia\OpenAPI\Parser;
use Utopia\OpenAPI\Specification;
use Utopia\OpenAPI\Version;

final class CrossVersionFixtureTest extends TestCase
{
    /** @return iterable<string, array{string, Version, string}> */
    public static function specificationProvider(): iterable
    {
        yield 'OpenAPI 2.0' => ['openapi-2.0.json', Version::V2, '2.0'];
        yield 'OpenAPI 3.0' => ['openapi-3.0.json', Version::V3_0, '3.0.3'];
        yield 'OpenAPI 3.1' => ['openapi-3.1.json', Version::V3_1, '3.1.1'];
    }

    #[DataProvider('specificationProvider')]
    public function testEquivalentDocumentsProduceEquivalentCanonicalBehavior(
        string $fixture,
        Version $version,
        string $sourceVersion,
    ): void {
        $specification = $this->parseFixture($fixture);

        self::assertSame($version, $specification->version);
        self::assertSame($sourceVersion, $specification->sourceVersion);
        self::assertSame('Pet API', $specification->info->title);
        self::assertSame('Cross-version parser fixture', $specification->info->description);
        self::assertSame('1.0.0', $specification->info->version);
        self::assertSame('platform', $specification->info->extensions['x-owner']);
        self::assertSame('https://api.example.com/v1', $specification->servers[0]->url);

        self::assertSame(['pets'], array_keys($specification->tags));
        self::assertSame('Pet operations', $specification->tags['pets']->description);
        self::assertSame(['/pets', '/pets/{id}'], array_keys($specification->paths));
        self::assertCount(3, $specification->operations());
        self::assertCount(3, $specification->operationsByTag('pets'));

        $create = $specification->paths['/pets']->operation(HttpMethod::POST);
        self::assertNotNull($create);
        self::assertSame('createPet', $create->id);
        self::assertSame('Create a pet', $create->summary);
        self::assertSame('stable', $create->extensions['x-stability']);
        self::assertNotNull($create->requestBody);
        self::assertTrue($create->requestBody->required);
        self::assertSame('Pet to create', $create->requestBody->description);
        self::assertSame(['application/json'], array_keys($create->requestBody->content));
        self::assertInstanceOf(ReferenceSchema::class, $create->requestBody->content['application/json']->schema);
        self::assertSame($this->petReference($version), $create->requestBody->content['application/json']->schema->reference);

        self::assertSame([201], array_keys($create->responses));
        self::assertSame('Created', $create->responses['201']->description);
        self::assertSame(['X-Request-Id'], array_keys($create->responses['201']->headers));
        self::assertInstanceOf(StringSchema::class, $create->responses['201']->headers['X-Request-Id']->schema);
        self::assertSame(['application/json'], array_keys($create->responses['201']->content));
        self::assertInstanceOf(ReferenceSchema::class, $create->responses['201']->content['application/json']->schema);

        $get = $specification->paths['/pets/{id}']->operation(HttpMethod::GET);
        self::assertNotNull($get);
        self::assertSame('getPet', $get->id);
        self::assertCount(1, $get->parameters);
        self::assertSame('id', $get->parameters[0]->name);
        self::assertSame(ParameterLocation::PATH, $get->parameters[0]->location);
        self::assertTrue($get->parameters[0]->required);
        self::assertSame('Operation identifier', $get->parameters[0]->description);
        self::assertSame([200, 'default'], array_keys($get->responses));
        self::assertSame('Unexpected error', $get->responses['default']->description);

        self::assertCount(2, $specification->security);
        self::assertSame(['Project', 'Basic'], array_keys($specification->security[0]->schemes));
        self::assertSame(['Project'], array_keys($specification->security[1]->schemes));
        self::assertSame($specification->security, $get->security);
        self::assertSame(['Project', 'Basic'], $get->acceptedSecuritySchemeNames());
        self::assertSame(['Project'], $get->requiredSecuritySchemeNames());
        $delete = $specification->paths['/pets/{id}']->operation(HttpMethod::DELETE);
        self::assertNotNull($delete);
        self::assertSame([], $delete->security);
        self::assertSame([], $delete->acceptedSecuritySchemeNames());
        self::assertSame([], $delete->requiredSecuritySchemeNames());

        self::assertSame(SecuritySchemeType::API_KEY, $specification->securitySchemes['Project']->type);
        self::assertSame('X-Project', $specification->securitySchemes['Project']->name);
        self::assertSame(ParameterLocation::HEADER, $specification->securitySchemes['Project']->location);
        self::assertSame(SecuritySchemeType::HTTP, $specification->securitySchemes['Basic']->type);
        self::assertSame('basic', $specification->securitySchemes['Basic']->scheme);

        $pet = $specification->schemas['Pet'];
        self::assertInstanceOf(ObjectSchema::class, $pet);
        self::assertSame('A pet', $pet->description);
        self::assertSame(['id', 'name'], $pet->required);
        self::assertFalse($pet->additionalProperties);
        self::assertSame(['id', 'name', 'nickname', 'parent', 'status'], array_keys($pet->properties));
        self::assertInstanceOf(IntegerSchema::class, $pet->properties['id']);
        self::assertSame('int64', $pet->properties['id']->format);
        self::assertInstanceOf(StringSchema::class, $pet->properties['name']);
        self::assertSame(1, $pet->properties['name']->minLength);
        self::assertFalse($pet->properties['nickname']->nullable);
        self::assertInstanceOf(ReferenceSchema::class, $pet->properties['parent']);
        self::assertSame($this->petReference($version), $pet->properties['parent']->reference);
        self::assertSame(['available', 'adopted'], $pet->properties['status']->enum);
    }

    #[DataProvider('specificationProvider')]
    public function testFixtureCanBeParsedFromJsonAndDecodedArray(
        string $fixture,
        Version $version,
        string $sourceVersion,
    ): void {
        $json = $this->fixtureContents($fixture);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $fromJson = Parser::parse($json, $version);
        $fromArray = Parser::parse($decoded, $version);

        self::assertEquals($fromJson, $fromArray);
        self::assertSame($sourceVersion, $fromArray->sourceVersion);
    }

    public function testFixturesHaveEquivalentSemanticSnapshots(): void
    {
        $snapshots = [];
        foreach (self::specificationProvider() as [$fixture]) {
            $snapshots[] = $this->semanticSnapshot($this->parseFixture($fixture));
        }

        self::assertSame($snapshots[0], $snapshots[1]);
        self::assertSame($snapshots[1], $snapshots[2]);
    }

    private function parseFixture(string $fixture): Specification
    {
        return Parser::parse($this->fixtureContents($fixture));
    }

    private function fixtureContents(string $fixture): string
    {
        $contents = file_get_contents(__DIR__ . '/Fixtures/' . $fixture);
        self::assertNotFalse($contents, "Unable to read fixture {$fixture}");

        return $contents;
    }

    private function petReference(Version $version): string
    {
        return $version === Version::V2
            ? '#/definitions/Pet'
            : '#/components/schemas/Pet';
    }

    /** @return array<string, mixed> */
    private function semanticSnapshot(Specification $specification): array
    {
        $create = $specification->paths['/pets']->operation(HttpMethod::POST);
        $get = $specification->paths['/pets/{id}']->operation(HttpMethod::GET);
        $delete = $specification->paths['/pets/{id}']->operation(HttpMethod::DELETE);
        $pet = $specification->schemas['Pet'];

        self::assertNotNull($create);
        self::assertNotNull($get);
        self::assertNotNull($delete);
        self::assertNotNull($create->requestBody);
        self::assertInstanceOf(ObjectSchema::class, $pet);
        self::assertInstanceOf(StringSchema::class, $pet->properties['name']);

        return [
            'info' => [$specification->info->title, $specification->info->description, $specification->info->version],
            'servers' => array_map(static fn(Server $server): string => $server->url, $specification->servers),
            'tags' => array_keys($specification->tags),
            'operations' => array_map(
                static fn(Operation $operation): array => [$operation->id, $operation->method->value, $operation->path, $operation->tags],
                $specification->operations(),
            ),
            'create' => [
                'requestMedia' => array_keys($create->requestBody->content),
                'requestRequired' => $create->requestBody->required,
                'responseCodes' => array_keys($create->responses),
                'responseMedia' => array_keys($create->responses['201']->content),
                'headers' => array_keys($create->responses['201']->headers),
            ],
            'get' => [
                'parameter' => [$get->parameters[0]->name, $get->parameters[0]->location->value, $get->parameters[0]->description],
                'responses' => array_keys($get->responses),
                'security' => array_map(static fn(SecurityRequirement $requirement): array => $requirement->schemes, $get->security),
            ],
            'deleteSecurity' => $delete->security,
            'securitySchemes' => array_map(
                static fn(SecurityScheme $scheme): array => [$scheme->type->value, $scheme->name, $scheme->location?->value, $scheme->scheme],
                $specification->securitySchemes,
            ),
            'pet' => [
                'description' => $pet->description,
                'required' => $pet->required,
                'additionalProperties' => $pet->additionalProperties,
                'properties' => array_keys($pet->properties),
                'idFormat' => $pet->properties['id']->format,
                'nameMinimum' => $pet->properties['name']->minLength,
                'nicknameNullable' => $pet->properties['nickname']->nullable,
                'statusEnum' => $pet->properties['status']->enum,
            ],
        ];
    }
}
