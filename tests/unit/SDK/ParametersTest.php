<?php

declare(strict_types=1);

namespace Tests\Unit\SDK;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Specification\Format;
use PHPUnit\Framework\TestCase;
use Utopia\Http\Http;

/**
 * SDKs pass arguments positionally: required params first, then optional ones,
 * each in declaration order. `app/config/sdks/parameters.lock.json` records that
 * order for every SDK method, so a change that would shift existing positional
 * calls in the next SDK release fails here instead of in users' code.
 */
final class ParametersTest extends TestCase
{
    private const string LOCK = __DIR__ . '/../../../app/config/sdks/parameters.lock.json';

    /**
     * @var array<string, array<string, list<string>>> Platform to SDK method to its params in SDK order.
     */
    private static array $methods = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../app/controllers/general.php';

        foreach (Http::getRoutes() as $routes) {
            foreach ($routes as $route) {
                if (!$route->getLabel('docs', true) || $route->getLabel('mock', false)) {
                    continue;
                }

                $sdk = $route->getLabel('sdk', false);
                $methods = \is_array($sdk) ? $sdk : [$sdk];

                foreach ($methods as $method) {
                    if (!$method instanceof Method) {
                        continue;
                    }

                    // Mirrors Format::getMethodParameters(): what the spec emits for this method
                    $params = \array_merge($route->getParams(), $method->getAdditionalParameters());
                    foreach ($method->getParameters() as $parameter) {
                        $name = $parameter->getName();
                        if ($parameter->getHide()) {
                            unset($params[$name]);
                            continue;
                        }
                        $params[$name] ??= ['optional' => $parameter->getOptional()];
                        if ($parameter->hasOptional()) {
                            $params[$name]['optional'] = $parameter->getOptional();
                        }
                    }

                    // The SDK generator lists path (and GET/DELETE query) params before body
                    // params, then stably sorts required ones first. Path params are required.
                    $segments = \explode('/', $route->getPath());
                    $listed = [];
                    $body = [];
                    foreach ($params as $name => $param) {
                        if (($param['deprecated'] ?? false) === true) {
                            continue;
                        }
                        $path = \array_intersect([':' . $name, ...\array_map(fn (string $alias) => ':' . $alias, $param['aliases'] ?? [])], $segments) !== [];
                        $entry = ['name' => $name, 'required' => $path || Format::isRequired((bool) $param['optional'], $method->getNamespace() . '.' . $method->getMethodName(), $name)];
                        if ($path || \in_array(\array_values($route->getMethods())[0], ['GET', 'DELETE'], true)) {
                            $listed[] = $entry;
                        } else {
                            $body[] = $entry;
                        }
                    }
                    $ordered = [...$listed, ...$body];
                    \usort($ordered, fn (array $left, array $right) => (int) $right['required'] <=> (int) $left['required']);
                    $names = \array_column($ordered, 'name');

                    if (\count($methods) > 1 && !empty($method->getParameters())) {
                        $own = \array_map(fn ($parameter) => $parameter->getName(), $method->getParameters());
                        $names = \array_values(\array_intersect($names, $own));
                    }

                    foreach ($method->getAuth() as $auth) {
                        $platform = $auth instanceof AuthType ? $auth->getPlatform() : null;
                        if ($platform !== null) {
                            self::$methods[$platform][$method->getNamespace() . '.' . $method->getMethodName()] = $names;
                        }
                    }
                }
            }
        }

        \ksort(self::$methods);
        foreach (self::$methods as &$methods) {
            \ksort($methods);
        }
    }

    public function testReleasedOrderIsKept(): void
    {
        foreach ($this->locked() as $platform => $methods) {
            foreach ($methods as $name => $locked) {
                $this->assertArrayHasKey($name, self::$methods[$platform] ?? [], "SDK method '{$name}' was removed from the {$platform} SDKs. Removing a method breaks every call to it; if this is an intended breaking change, delete it from app/config/sdks/parameters.lock.json.");

                $current = self::$methods[$platform][$name];
                $order = "\nLocked:  " . \implode(', ', $locked) . "\nCurrent: " . \implode(', ', $current);

                $this->assertSame(
                    $locked,
                    \array_values(\array_intersect($current, $locked)),
                    "SDK method '{$name}' ({$platform}) reordered or removed a param.{$order}\nPositional calls in released SDKs would pass values to the wrong params. Keep the locked order; if this is an intended breaking change, update app/config/sdks/parameters.lock.json."
                );

                foreach (\array_slice($current, 0, \count($locked)) as $param) {
                    $this->assertContains(
                        $param,
                        $locked,
                        "SDK method '{$name}' ({$platform}) has new param '{$param}' ahead of existing ones.{$order}\nSDKs order params required first, then as declared. Make '{$param}' optional and declare it last, then append it in app/config/sdks/parameters.lock.json."
                    );
                }
            }
        }
    }

    public function testMethodsAreLocked(): void
    {
        $locked = $this->locked();

        foreach (self::$methods as $platform => $methods) {
            foreach ($methods as $name => $params) {
                $this->assertSame(
                    $params,
                    $locked[$platform][$name] ?? null,
                    "SDK method '{$name}' ({$platform}) does not match app/config/sdks/parameters.lock.json. Set it under '{$platform}' to:\n\"{$name}\": " . \json_encode($params, JSON_UNESCAPED_SLASHES)
                );
            }
        }
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private function locked(): array
    {
        return \json_decode((string) \file_get_contents(self::LOCK), true, flags: JSON_THROW_ON_ERROR);
    }
}
