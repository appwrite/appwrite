<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;

/**
 * Every response model default must encode as the JSON type its rule declares.
 *
 * A rule's default is emitted whenever the attribute is absent from the
 * document, so it ships to clients on freshly created resources. PHP encodes an
 * empty array as `[]`, which means an object-shaped rule defaulting to `[]`
 * sends a JSON array where the OpenAPI spec promises an object. Clients
 * generated from that spec then fail to deserialize a response even though the
 * request succeeded — this is what broke project creation, where a new project
 * returned `"onboarding": []` for a field typed as an object.
 *
 * The check is deliberately shape-based rather than PHP-type-based: it encodes
 * the default and compares the resulting JSON token to the shape the rule
 * declares. That handles the cases a type check gets wrong, such as a non-empty
 * associative array correctly encoding as an object.
 */
final class ModelDefaultTest extends TestCase
{
    private static ?Response $response = null;

    /**
     * Maps each scalar rule type to the JSON token its default must produce.
     * Anything absent from this map is a model reference or a union of model
     * references, both of which encode as objects.
     */
    private const SHAPES = [
        Model::TYPE_STRING => 'string',
        Model::TYPE_DATETIME => 'string',
        Model::TYPE_ENUM => 'string',
        Model::TYPE_ID => 'string',
        Model::TYPE_PAYLOAD => 'string',
        Model::TYPE_INTEGER => 'number',
        Model::TYPE_FLOAT => 'number',
        Model::TYPE_BOOLEAN => 'boolean',
        Model::TYPE_JSON => 'object',
        Model::TYPE_RELATIONSHIP => 'object',
        Model::TYPE_ARRAY => 'array',
    ];

    #[DataProvider('provideModels')]
    public function testRuleDefaultsMatchDeclaredJsonType(Model $model): void
    {
        $violations = [];

        foreach ($model->getRules() as $name => $rule) {
            if (! \array_key_exists('default', $rule)) {
                continue;
            }

            $default = $rule['default'];

            // A null default marks an optional field and encodes as null.
            if ($default === null) {
                continue;
            }

            $expected = self::expectedShape($rule);
            $actual = self::jsonShape($default);

            if ($actual !== $expected) {
                $violations[] = \sprintf(
                    '%s::%s declares %s so its default must encode as a JSON %s, but %s encodes as %s',
                    $model->getName(),
                    $name,
                    self::describeType($rule['type'] ?? 'unknown'),
                    $expected,
                    \json_encode($default),
                    $actual
                );
            }
        }

        $this->assertSame([], $violations, \sprintf(
            "Response model defaults must encode as the JSON type they declare.\n" .
            "Use new \\stdClass() for object-shaped rules so they encode as {} rather than [].\n\n%s\n",
            \implode("\n", $violations)
        ));
    }

    /**
     * Guards the provider itself: an empty or partial model registry would let
     * every case above pass without inspecting anything.
     */
    public function testProviderCoversTheModelRegistry(): void
    {
        $models = \iterator_to_array(self::provideModels());

        $this->assertGreaterThan(100, \count($models), 'Expected the full response model registry.');
        $this->assertArrayHasKey('project', $models);
    }

    /**
     * Keyed by registry key rather than model name: names are not unique, as
     * both Error/ErrorDev and the two Index models report the same name.
     *
     * @return iterable<string, array{Model}>
     */
    public static function provideModels(): iterable
    {
        foreach (self::response()->getModels() as $key => $model) {
            // "Any" models carry no rules to validate.
            if ($model->isAny()) {
                continue;
            }

            yield $key => [$model];
        }
    }

    /**
     * The JSON token a rule's default is required to produce.
     *
     * @param array<string, mixed> $rule
     */
    private static function expectedShape(array $rule): string
    {
        if (($rule['array'] ?? false) === true) {
            return 'array';
        }

        $type = $rule['type'] ?? null;

        // A union of model references, e.g. user.hashOptions.
        if (! \is_string($type)) {
            return 'object';
        }

        // Unmapped types are model references, which encode as objects.
        return self::SHAPES[$type] ?? 'object';
    }

    /**
     * The JSON token a value actually encodes to.
     */
    private static function jsonShape(mixed $value): string
    {
        $encoded = \json_encode($value);

        return match (true) {
            \str_starts_with($encoded, '{') => 'object',
            \str_starts_with($encoded, '[') => 'array',
            \str_starts_with($encoded, '"') => 'string',
            $encoded === 'true', $encoded === 'false' => 'boolean',
            $encoded === 'null' => 'null',
            default => 'number',
        };
    }

    private static function describeType(mixed $type): string
    {
        return \is_array($type)
            ? 'a union of ' . \count($type) . ' models'
            : (string) $type;
    }

    private static function response(): Response
    {
        return self::$response ??= new Response(new SwooleResponse());
    }
}
