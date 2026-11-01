<?php

namespace Tests\Compat\Config;

require_once __DIR__ . '/../php-std/Typed.php';
require_once __DIR__ . '/Schemas.php';

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\PhpStd\Typed;
use Tests\Compat\Session;
use Utopia\Config\Config;
use Utopia\Config\Parser;
use Utopia\Config\Parser\Dotenv;
use Utopia\Config\Parser\JSON;
use Utopia\Config\Parser\None;
use Utopia\Config\Parser\PHP;
use Utopia\Config\Parser\YAML;
use Utopia\Config\Source;
use Utopia\Config\Source\Environment;
use Utopia\Config\Source\File;
use Utopia\Config\Source\Variable;

/**
 * Maps tests/compat/config/spec.json operations onto utopia-php/config. Glue only: no logic.
 *
 * Values cross as Typed (tests/compat/php-std/Typed.php): exact array keys,
 * and a loaded config as {"$config": class, "properties": [[name, value]...]}.
 * Schemas are the classes in Schemas.php, named without their namespace.
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        return [
            'get_param' => ['Read a nested parameter with a dotted key', function (Session $s) {
                Config::setParam('platform', ['hostname' => 'cloud.appwrite.io', 'region' => ['name' => 'fra']]);

                return fn () => Config::getParam('platform.region.name');
            }],
            'get_param_flat' => ['Read a top-level parameter', function (Session $s) {
                Config::setParam('version', '1.8.0');

                return fn () => Config::getParam('version');
            }],
            'dotenv' => ['Parse a ten-line dotenv file', function (Session $s) {
                $env = self::dotenv();
                $parser = new Dotenv();

                return fn () => $parser->parse($env);
            }],
            'load' => ['Load a five-property config from parsed variables', function (Session $s) {
                $source = new Variable(['phpKey' => 'a', 'jsonKey' => 'b', 'yaml-key' => 'c', 'yml_key' => 'd', 'ENV_KEY' => 'e']);
                $parser = new None();

                return fn () => Config::load($source, $parser, TestConfig::class);
            }],
        ];
    }

    public static function dotenv(): string
    {
        $lines = [];
        for ($i = 0; $i < 10; $i++) {
            $lines[] = "KEY_{$i}=value-{$i} # comment";
        }

        return implode("\n", $lines);
    }

    public function operations(): array
    {
        return [
            'config.reset_params' => function (array $a, Session $s) {
                Config::$params = [];

                return;
            },
            'config.set_param' => function (array $a, Session $s) {
                Config::setParam($a['key'], self::value($a['value'] ?? null, $s));

                return;
            },
            'config.get_param' => fn (array $a, Session $s) => self::dump(
                \array_key_exists('default', $a) ? Config::getParam($a['key'], self::value($a['default'], $s)) : Config::getParam($a['key'])
            ),
            'config.params' => function (array $a, Session $s) {
                $saved = Config::$params;
                Config::$params = [];
                $results = [];
                try {
                    foreach ($a['calls'] as $call) {
                        if ($call['op'] === 'set') {
                            Config::setParam($call['key'], Typed::decode($call['value'] ?? null));
                            $results[] = null;
                        } else {
                            $results[] = self::dump(Config::getParam($call['key'], 'default'));
                        }
                    }
                } finally {
                    Config::$params = $saved;
                }

                return $results;
            },
            'config.source' => function (array $a, Session $s) {
                $contents = self::source($a['source'], $s)->getContents();
                if (isset($a['keys'])) {
                    $contents = array_intersect_key((array) $contents, array_flip($a['keys']));
                }

                return self::dump($contents);
            },
            'config.parse' => fn (array $a, Session $s) => self::dump(self::parser($a['parser'])->parse(
                self::value($a['contents'] ?? null, $s),
                isset($a['schema']) ? new \ReflectionClass(self::schema($a['schema'])) : null,
            )),
            'config.load' => function (array $a, Session $s) {
                $config = Config::load(self::source($a['source'], $s), self::parser($a['parser']), self::schema($a['schema']));

                return isset($a['handle']) && $a['handle'] ? $s->handle($config) : self::dump($config);
            },
        ];
    }

    private static function parser(string $name): Parser
    {
        return match ($name) {
            'dotenv' => new Dotenv(),
            'json' => new JSON(),
            'yaml' => new YAML(),
            'php' => new PHP(),
            'none' => new None(),
            default => throw new Fault("unknown parser {$name}"),
        };
    }

    /**
     * @return class-string
     */
    private static function schema(string $name): string
    {
        $class = __NAMESPACE__ . '\\' . $name;

        return class_exists($class) ? $class : throw new Fault("unknown schema {$name}");
    }

    private static function source(array $source, Session $s): Source
    {
        switch ($source['kind']) {
            case 'variable':
                return new Variable(self::value($source['value'] ?? null, $s));
            case 'file':
                if (isset($source['contents'])) {
                    @mkdir(\dirname($source['path']), 0777, true);
                    file_put_contents($source['path'], Typed::decode($source['contents']));
                }

                return new File($source['path']);
            case 'environment':
                foreach ($source['set'] ?? [] as $key => $value) {
                    putenv("{$key}={$value}");
                }
                try {
                    return new Environment();
                } finally {
                    foreach ($source['set'] ?? [] as $key => $value) {
                        putenv((string) $key);
                    }
                }
            default:
                throw new Fault("unknown source {$source['kind']}");
        }
    }

    /**
     * A Typed value where {"$handle": id} is the config that handle holds.
     */
    private static function value(mixed $value, Session $s): mixed
    {
        if (\is_array($value) && \count($value) === 1 && isset($value['$handle'])) {
            return $s->get($value);
        }
        $value = Typed::decode($value);
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                if (\is_array($item) && \count($item) === 1 && isset($item['$handle'])) {
                    $value[$key] = $s->get($item);
                }
            }
        }

        return $value;
    }

    private static function dump(mixed $value): mixed
    {
        if (\is_array($value)) {
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = [$key, self::dump($item)];
            }

            return array_is_list($value) ? array_map(fn (array $p) => $p[1], $pairs) : ['$array' => $pairs];
        }
        if (\is_object($value) && !$value instanceof \stdClass) {
            $properties = [];
            foreach ((new \ReflectionObject($value))->getProperties() as $property) {
                $properties[] = [$property->name, $property->isInitialized($value) ? self::dump($property->getValue($value)) : ['$uninitialized' => true]];
            }

            return ['$config' => substr($value::class, \strlen(__NAMESPACE__) + 1), 'properties' => $properties];
        }

        return Typed::dump($value);
    }
}
