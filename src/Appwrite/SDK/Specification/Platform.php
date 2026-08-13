<?php

namespace Appwrite\SDK\Specification;

class Platform
{
    private const HTTP_METHODS = [
        'delete',
        'get',
        'head',
        'options',
        'patch',
        'post',
        'put',
        'trace',
    ];

    /**
     * Create the platform view consumed by existing SDK generator releases.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    public static function filter(array $spec, string $platform): array
    {
        $platformConfig = $spec['x-appwrite']['platforms'][$platform] ?? [];
        $authCount = $platformConfig['authCount'] ?? 0;
        $securitySchemes = [];
        $schemes = $spec['components']['securitySchemes'] ?? [];
        $schemeNames = $platformConfig['securitySchemes'] ?? \array_keys($schemes);

        foreach ($schemeNames as $name) {
            $scheme = $schemes[$name] ?? null;
            if (!\is_array($scheme)) {
                continue;
            }

            $platforms = $scheme['x-appwrite']['platforms'] ?? [];
            if (!\in_array($platform, $platforms, true)) {
                continue;
            }

            $override = $scheme['x-appwrite']['overrides'][$platform] ?? null;
            if (\is_array($override)) {
                $metadata = $scheme['x-appwrite'];
                unset($metadata['platforms'], $metadata['overrides']);
                if (!empty($metadata)) {
                    $override['x-appwrite'] = \array_merge($metadata, $override['x-appwrite'] ?? []);
                }
                $scheme = $override;
            } else {
                unset($scheme['x-appwrite']['platforms'], $scheme['x-appwrite']['overrides']);
                if (empty($scheme['x-appwrite'])) {
                    unset($scheme['x-appwrite']);
                }
            }

            $securitySchemes[$name] = $scheme;
        }

        $spec['components']['securitySchemes'] = $securitySchemes;
        $securityNames = \array_fill_keys(\array_keys($securitySchemes), true);

        foreach ($spec['paths'] ?? [] as $path => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (!\in_array($method, self::HTTP_METHODS, true)) {
                    continue;
                }

                $platforms = $operation['x-appwrite']['available-platforms']
                    ?? $operation['x-appwrite']['platforms']
                    ?? [];
                if (!empty($platforms) && !\in_array($platform, $platforms, true)) {
                    unset($spec['paths'][$path][$method]);
                    continue;
                }
                unset($operation['x-appwrite']['available-platforms']);

                $methods = $operation['x-appwrite']['methods'] ?? null;
                if (\is_array($methods)) {
                    $methods = \array_values(\array_filter(
                        $methods,
                        fn (array $item): bool => \in_array(
                            $platform,
                            $item['available-platforms'] ?? $item['platforms'] ?? [],
                            true,
                        ),
                    ));

                    if (empty($methods)) {
                        unset($spec['paths'][$path][$method]);
                        continue;
                    }

                    foreach ($methods as &$item) {
                        $item['auth'] = self::filterAuth($item['auth'] ?? [], $securityNames, $authCount);
                        unset($item['platforms'], $item['available-platforms']);
                    }
                    unset($item);

                    $operation['x-appwrite']['methods'] = $methods;
                }

                $operation['x-appwrite']['auth'] = self::filterAuth(
                    $operation['x-appwrite']['auth'] ?? [],
                    $securityNames,
                    $authCount,
                );

                foreach ($operation['x-appwrite']['location-auth'] ?? [] as $name) {
                    if (isset($securityNames[$name])) {
                        $operation['x-appwrite']['auth'][$name] = [];
                    }
                }
                unset($operation['x-appwrite']['location-auth']);

                foreach ($operation['security'] ?? [] as $index => $security) {
                    $operation['security'][$index] = \array_intersect_key($security, $securityNames);
                }

                $spec['paths'][$path][$method] = $operation;
            }

            if (empty($spec['paths'][$path])) {
                unset($spec['paths'][$path]);
            }
        }

        return $spec;
    }

    /**
     * @param array<string, mixed> $auth
     * @param array<string, bool> $securityNames
     * @return array<string, mixed>
     */
    private static function filterAuth(array $auth, array $securityNames, int $count): array
    {
        return \array_slice(\array_intersect_key($auth, $securityNames), 0, $count, true);
    }
}
