<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Attribute\ConfigKey;
use Utopia\Config\Attribute\Key;
use Utopia\Config\Config;
use Utopia\Config\Exception\Load;
use Utopia\Config\Parser;
use Utopia\Config\Parser\Dotenv;
use Utopia\Config\Parser\JSON;
use Utopia\Config\Parser\None;
use Utopia\Config\Parser\PHP;
use Utopia\Config\Parser\YAML;
use Utopia\Config\Source\Environment;
use Utopia\Config\Source\File;
use Utopia\Config\Source\Variable;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

final class ConfigTest extends TestCase
{
    protected function setUp(): void {}

    protected function tearDown(): void {}

    public function testFileSource(): void
    {
        $config = Config::load(new File(__DIR__ . '/../resources/config.json'), new JSON(), TestConfig::class);
        $this->assertSame('customValue', $config->jsonKey);
    }

    public function testFileSourceException(): void
    {
        $this->expectException(Load::class);
        Config::load(new File(__DIR__ . '/../resources/non-existing.json'), new JSON(), TestConfig::class);
    }

    public function testVariableSource(): void
    {
        $config = Config::load(new Variable([
            'phpKey' => 'aValue',
            'ENV_KEY' => 'aValue',
        ]), new None(), TestConfig::class);
        $this->assertSame('aValue', $config->phpKey);
        $this->assertSame('aValue', $config->envKey);

        $config = Config::load(new Variable('ENV_KEY=aValue'), new Dotenv(), TestConfig::class);
        $this->assertSame('aValue', $config->envKey);

        $config = Config::load(new Environment(), new None(), TestEnvConfig::class);
        $this->assertSame('hello', $config->key1);
        $this->assertSame('world', $config->key2);
    }

    /**
     * @return \Iterator<(int | string), mixed>
     */
    public static function provideAdapterScenarios(): \Iterator
    {
        yield [
            'adapter' => PHP::class,
            'extension' => 'php',
            'key' => 'phpKey',
        ];
        yield [
            'adapter' => JSON::class,
            'extension' => 'json',
            'key' => 'jsonKey',
        ];
        yield [
            'adapter' => YAML::class,
            'extension' => 'yaml',
            'key' => 'yamlKey',
        ];
        yield [
            'adapter' => YAML::class,
            'extension' => 'yml',
            'key' => 'ymlKey',
        ];
        yield [
            'adapter' => Dotenv::class,
            'extension' => 'env',
            'key' => 'envKey',
        ];
    }

    /**
     * @param  class-string  $adapter
     */
    #[DataProvider('provideAdapterScenarios')]
    public function testAdapters(string $adapter, string $extension, string $key): void
    {
        $adapter = new $adapter();
        if (! ($adapter instanceof Parser)) {
            throw new \Exception('Test scenario includes invalid adapter.');
        }

        $config = Config::load(new File(__DIR__ . '/../resources/config.' . $extension), $adapter, TestConfig::class);

        $this->assertSame('customValue', $config->$key);
    }

    public function testSubConfigs(): void
    {
        $config1 = Config::load(new Variable('ENV_KEY=envValue'), new Dotenv(), TestConfig::class);
        $config2 = Config::load(new Variable('yml_key: ymlValue'), new YAML(), TestConfig::class);

        $config = Config::load(new Variable([
            'config1' => $config1,
            'config2' => $config2,
            'rootKey' => 'rootValue',
        ]), new None(), TestGroupConfig::class);

        $this->assertSame('rootValue', $config->rootKey);
        $this->assertSame('envValue', $config->config1->envKey);
        $this->assertSame('ymlValue', $config->config2->ymlKey);
    }

    public function testExceptionWithMethod(): void
    {
        $this->expectException(Load::class);
        Config::load(new Variable('KEY=value'), new Dotenv(), TestConfigWithMethod::class);
    }

    public function testExceptionWithExtraProperties(): void
    {
        $this->expectException(Load::class);
        Config::load(new Variable('KEY=value'), new Dotenv(), TestConfigWithExtraProperties::class);
    }

    public function testExceptionValidator(): void
    {
        $this->expectException(Load::class);
        Config::load(new Variable('KEY=too_long_value_that_will_not_get_accepted'), new Dotenv(), TestConfigRequired::class);
    }

    public function testExceptionRequired(): void
    {
        $this->expectException(Load::class);
        Config::load(new Variable('SOME_OTHER_KEY=value'), new Dotenv(), TestConfigRequired::class);
    }

    public function testExceptionWithoutType(): void
    {
        $this->expectException(Load::class);
        Config::load(new Variable('KEY=value'), new Dotenv(), TestConfigWithoutType::class);
    }

    public function testNestedValues(): void
    {
        $jsons = [
            <<<JSON
                {
                "db.host": "docker.internal",
                "db.config.tls": true
                }
            JSON,
            <<<JSON
                {
                  "db": {
                    "host": "docker.internal",
                      "config": {
                        "tls": true
                      }
                  }
                }
            JSON,
            <<<JSON
                {
                  "db": {
                    "host": "docker.internal",
                      "config.tls": true
                  }
                }
            JSON,
            <<<JSON
                {
                  "db.host": "docker.internal",
                  "db": {
                      "config": {
                        "tls": true
                      }
                  }
                }
            JSON,
        ];

        foreach ($jsons as $json) {
            $config = Config::load(new Variable($json), new JSON(), TestNestedValueConfig::class);
            $this->assertSame('docker.internal', $config->dbHost);
            $this->assertTrue($config->tls);
        }
    }

    public function testRequiredNullablePresentNull(): void
    {
        $config = Config::load(new Variable('{"name": null}'), new JSON(), TestNullableConfig::class);
        $this->assertNull($config->name);
    }

    public function testRequiredNullableNestedPresentNull(): void
    {
        $config = Config::load(new Variable('{"db": {"name": null}}'), new JSON(), TestNestedNullableConfig::class);
        $this->assertNull($config->dbName);
    }

    public function testOptionalNullablePresentNullIsAssigned(): void
    {
        $config = Config::load(new Variable('{"name": null}'), new JSON(), TestOptionalNullableConfig::class);
        $this->assertNull($config->name);
    }

    public function testValueTypeMismatchThrowsLoad(): void
    {
        // A loose validator accepts the string, but assigning it to the int
        // property would raise TypeError — the loader must surface Load instead.
        $this->expectException(Load::class);
        Config::load(new Variable('PORT=abc'), new Dotenv(), TestTypeMismatchConfig::class);
    }

    public function testConstructorArgumentThrowsLoad(): void
    {
        // A required constructor argument must not blow up as ArgumentCountError.
        $this->expectException(Load::class);
        Config::load(new Variable('KEY=value'), new Dotenv(), TestConstructorConfig::class);
    }
}

// Schemas used for configs in test scenarios
class TestConfig
{
    #[Key('phpKey', new Text(1024, 0), required: false)]
    public string $phpKey;

    #[Key('jsonKey', new Text(1024, 0), required: false)]
    public string $jsonKey;

    #[Key('yaml-key', new Text(1024, 0), required: false)]
    public string $yamlKey;

    #[Key('yml_key', new Text(1024, 0), required: false)]
    public string $ymlKey;

    #[Key('ENV_KEY', new Text(1024, 0), required: false)]
    public string $envKey;
}

class TestGroupConfig
{
    #[ConfigKey]
    public TestConfig $config1;

    #[ConfigKey]
    public TestConfig $config2;

    #[Key('rootKey', new Text(1024, 0), required: true)]
    public string $rootKey;
}

class TestEnvConfig
{
    #[Key('_UTOPIA_KEY1', new Text(8, 0), required: true)]
    public string $key1;

    #[Key('_UTOPIA_KEY2', new Text(8, 0), required: true)]
    public string $key2;
}

class TestConfigRequired
{
    #[Key('key', new Text(8, 0), required: true)]
    public string $key;
}

class TestConfigWithMethod
{
    #[Key('key', new Text(1024, 0))]
    public string $key;

    public function convertKey(): string
    {
        return strtoupper($this->key);
    }
}

class TestConfigWithoutType
{
    // Intentionally untyped: at runtime we ensure the type is required
    #[Key('key', new Text(1024, 0))]
    public $key;
}

class TestConfigWithExtraProperties
{
    #[Key('KEY', new Text(1024, 0))]
    public string $key;

    public string $key2;
}

class TestNestedValueConfig
{
    #[Key('db.host', new Text(1024), required: true)]
    public string $dbHost;

    #[Key('db.config.tls', new Boolean(), required: true)]
    public bool $tls;
}

class TestNullableConfig
{
    #[Key('name', new Nullable(new Text(1024)), required: true)]
    public ?string $name = null;
}

class TestNestedNullableConfig
{
    #[Key('db.name', new Nullable(new Text(1024)), required: true)]
    public ?string $dbName = null;
}

class TestOptionalNullableConfig
{
    #[Key('name', new Nullable(new Text(1024)), required: false)]
    public ?string $name = 'unset';
}

class TestTypeMismatchConfig
{
    #[Key('PORT', new Text(1024, 0), required: true)]
    public int $port;
}

class TestConstructorConfig
{
    public function __construct(public string $required) {}
}
