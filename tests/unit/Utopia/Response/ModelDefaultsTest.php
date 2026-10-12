<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Format\OpenAPI3;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\DI\Container;
use Utopia\Http\Route;

/**
 * SDK models fill a field an older server does not send from its spec default. A required
 * field without a usable default makes every older server's response unreadable for that model,
 * so a field added to a response model needs a default its schema accepts, or `required => false`.
 */
final class ModelDefaultsTest extends TestCase
{
    private const string BASELINE = __DIR__ . '/model-defaults-baseline.json';

    public function testRequiredFieldsHaveDefaults(): void
    {
        $missing = $this->collectMissingDefaults();
        $baseline = \json_decode((string) \file_get_contents(self::BASELINE), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            [],
            \array_values(\array_diff($missing, $baseline)),
            'These required response fields have no default the spec can emit, so SDKs cannot read them from a server that predates them. Give each a default its schema accepts, or make it optional.',
        );
        $this->assertSame(
            [],
            \array_values(\array_diff($baseline, $missing)),
            'These fields now have a default. Remove them from ' . \basename(self::BASELINE) . '.',
        );
    }

    /**
     * Required scalar properties the spec emits without a default, as "model.property".
     *
     * @return list<string>
     */
    private function collectMissingDefaults(): array
    {
        Method::$processed = [];
        Method::$errors = [];

        $models = (new Response(new SwooleResponse()))->getModels();
        $routes = [];
        foreach ($models as $type => $model) {
            $routes[] = (new Route('GET', '/v1/tests/' . $type))
                ->desc('Get ' . $type)
                ->label('sdk', new Method(
                    namespace: 'test',
                    group: null,
                    name: 'get' . \ucfirst($type),
                    description: 'Get ' . $type . '.',
                    auth: [AuthType::ADMIN],
                    responses: [new SDKResponse(code: 200, model: $type)],
                ));
        }

        $spec = (new OpenAPI3(new Container(), [], $routes, \array_values($models), [], ['console' => 0], 'console'))->parse();

        $missing = [];
        foreach ($spec['components']['schemas'] as $name => $schema) {
            foreach ($schema['required'] ?? [] as $property) {
                $definition = $schema['properties'][$property] ?? null;
                // Nested models fall back field by field, so only scalar properties need their own default.
                if ($definition === null || \str_contains((string) \json_encode($definition), '"$ref"')) {
                    continue;
                }
                if (!\array_key_exists('default', $definition)) {
                    $missing[] = $name . '.' . $property;
                }
            }
        }

        \sort($missing);

        return $missing;
    }
}
