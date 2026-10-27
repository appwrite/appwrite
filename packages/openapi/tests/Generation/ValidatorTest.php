<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Tests\Generation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\OpenAPI\Exception\UnsafeValue;
use Utopia\OpenAPI\Generation\Rule;
use Utopia\OpenAPI\Generation\Validator;
use Utopia\OpenAPI\Parser;

final class ValidatorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function document(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Appwrite', 'version' => '1.8.0'],
            'servers' => [['url' => 'https://cloud.appwrite.io/v1']],
            'tags' => [['name' => 'teams']],
            'components' => [
                'securitySchemes' => ['Project' => ['type' => 'apiKey', 'name' => 'X-Appwrite-Project', 'in' => 'header']],
                'schemas' => [
                    'team' => [
                        'type' => 'object',
                        'properties' => [
                            '$id' => ['type' => 'string'],
                            'scope' => ['type' => 'string', 'title' => 'teams.read', 'enum' => ['teams.read']],
                            'country' => ['type' => 'string', 'title' => "Côte d'Ivoire", 'enum' => ['ci']],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/teams/{teamId}:activate' => [
                    'post' => [
                        'operationId' => 'activateTeam',
                        'tags' => ['teams'],
                        'parameters' => [['name' => 'teamId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                        'responses' => [
                            '200' => ['description' => 'Team', 'content' => ['application/json; charset=utf-8' => ['schema' => ['$ref' => '#/components/schemas/team']]]],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testAcceptsASpecificationThatGeneratesSafely(): void
    {
        $this->expectNotToPerformAssertions();

        Validator::validate(Parser::parse(self::document()));
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, Rule, string, string}> */
    public static function unsafe(): iterable
    {
        yield 'title' => [
            static fn (array $document): array => array_replace_recursive($document, ['info' => ['title' => 'App"write']]),
            Rule::Title, 'App"write', '#/info/title',
        ];
        yield 'server url' => [
            static fn (array $document): array => array_replace($document, ['servers' => [['url' => 'https://example.com/$x']]]),
            Rule::Url, 'https://example.com/$x', '#/servers/0/url',
        ];
        yield 'security scheme name' => [
            static function (array $document): array {
                $document['components']['securitySchemes']['Project']['name'] = 'X-"Project';

                return $document;
            },
            Rule::Name, 'X-"Project', '#/components/securitySchemes/Project/name',
        ];
        yield 'path' => [
            static function (array $document): array {
                $document['paths'] = ['/teams/"' => $document['paths']['/teams/{teamId}:activate']];
                $document['paths']['/teams/"']['post']['parameters'] = [];

                return $document;
            },
            Rule::Path, '/teams/"', '#/paths/~1teams~1"',
        ];
        yield 'operation id' => [
            static function (array $document): array {
                $document['paths']['/teams/{teamId}:activate']['post']['operationId'] = 'activate Team';

                return $document;
            },
            Rule::Name, 'activate Team', '#/paths/~1teams~1{teamId}:activate/post/operationId',
        ];
        yield 'parameter' => [
            static function (array $document): array {
                $document['paths']['/teams/{teamId}:activate']['post']['parameters'][] = ['name' => 'a b', 'in' => 'query', 'schema' => ['type' => 'string']];

                return $document;
            },
            Rule::Field, 'a b', '#/paths/~1teams~1{teamId}:activate/post/parameters/1/name',
        ];
        yield 'media type' => [
            static function (array $document): array {
                $document['paths']['/teams/{teamId}:activate']['post']['responses']['200']['content'] = ['application/json"' => ['schema' => ['type' => 'string']]];

                return $document;
            },
            Rule::MediaType, 'application/json"', '#/paths/~1teams~1{teamId}:activate/post/responses/200/content/application~1json"',
        ];
        yield 'property' => [
            static function (array $document): array {
                $document['components']['schemas']['team']['properties']['a.b'] = ['type' => 'string'];

                return $document;
            },
            Rule::Field, 'a.b', '#/components/schemas/team/properties/a.b',
        ];
        yield 'enum value' => [
            static function (array $document): array {
                $document['components']['schemas']['team']['properties']['scope']['enum'][] = "it's";

                return $document;
            },
            Rule::EnumValue, "it's", '#/components/schemas/team/properties/scope/enum/1',
        ];
        yield 'enum name' => [
            static function (array $document): array {
                $document['components']['schemas']['team']['properties']['scope']['title'] = "teams\nread";

                return $document;
            },
            Rule::EnumName, "teams\nread", '#/components/schemas/team/properties/scope/title',
        ];
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    #[DataProvider('unsafe')]
    public function testRefusesAnUnsafeValue(callable $change, Rule $rule, string $value, string $location): void
    {
        try {
            Validator::validate(Parser::parse($change(self::document())));
            self::fail('Expected the unsafe value to be refused');
        } catch (UnsafeValue $exception) {
            self::assertSame($rule, $exception->rule);
            self::assertSame($value, $exception->value);
            self::assertSame($location, $exception->location);
        }
    }
}
