<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Appwrite\Config\Adapter\Dotenv;
use Appwrite\Config\Adapter\PHP;
use Appwrite\Config\Config;
use Appwrite\Config\Exception\Load;
use Appwrite\Config\Exception\Parse;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $params;

    private string $directory;

    protected function setUp(): void
    {
        $this->params = Config::$params;
        Config::$params = [];
        $this->directory = \sys_get_temp_dir() . '/appwrite-config-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        Config::$params = $this->params;
        foreach (\array_diff(\scandir($this->directory) ?: [], ['.', '..']) as $file) {
            \unlink($this->directory . '/' . $file);
        }
        \rmdir($this->directory);
    }

    public function testGetParamResolvesFlatAndDottedKeys(): void
    {
        Config::setParam('key', 'value');
        Config::setParam('nested', ['child' => ['leaf' => 'leafValue'], 'zero' => 0]);

        $this->assertSame('value', Config::getParam('key'));
        $this->assertSame('leafValue', Config::getParam('nested.child.leaf'));
        $this->assertSame(['leaf' => 'leafValue'], Config::getParam('nested.child'));
        $this->assertSame(0, Config::getParam('nested.zero'));
        $this->assertSame('default', Config::getParam('missing', 'default'));
        $this->assertSame('default', Config::getParam('nested.missing', 'default'));
        $this->assertSame('default', Config::getParam('nested.child.leaf.deeper', 'default'));
    }

    public function testSetParamOverwrites(): void
    {
        Config::setParam('key', 'first');
        Config::setParam('key', 'second');

        $this->assertSame('second', Config::getParam('key'));
    }

    public function testPHPFileCanReadParamsLoadedBeforeIt(): void
    {
        Config::setParam('region', 'fra');
        $path = $this->write('platform.php', "<?php\n\nreturn ['hostname' => 'cloud.appwrite.io', 'region' => \\Appwrite\\Config\\Config::getParam('region')];\n");

        Config::load('platform', $path, new PHP());

        $this->assertSame('cloud.appwrite.io', Config::getParam('platform.hostname'));
        $this->assertSame('fra', Config::getParam('platform.region'));
    }

    public function testPHPFileThatReturnsNoArrayLeavesTheKeyUnset(): void
    {
        $path = $this->write('broken.php', "<?php\n\nreturn 'not an array';\n");

        try {
            Config::load('broken', $path, new PHP());
            $this->fail('Expected Load');
        } catch (Load) {
        }

        $this->assertArrayNotHasKey('broken', Config::$params);
    }

    public function testMissingFileLeavesTheExistingValueInPlace(): void
    {
        Config::setParam('platform', ['hostname' => 'kept']);

        try {
            Config::load('platform', $this->directory . '/missing.php', new PHP());
            $this->fail('Expected Load');
        } catch (Load) {
        }

        $this->assertSame('kept', Config::getParam('platform.hostname'));
    }

    public function testDotenvParsesValuesAndSkipsComments(): void
    {
        $config = (new Dotenv())->parse(<<<'ENV'
            # Leading comment
            KEY=keyValue
            WITH_COMMENT=commented # trailing comment

            URL=https://example.com/?a=b
            EMPTY=
            ENV);

        $this->assertSame([
            'KEY' => 'keyValue',
            'WITH_COMMENT' => 'commented',
            'URL' => 'https://example.com/?a=b',
            'EMPTY' => '',
        ], $config);
    }

    public function testDotenvRejectsALineWithoutAName(): void
    {
        $this->expectException(Parse::class);

        (new Dotenv())->parse("KEY=value\n=orphan\n");
    }

    public function testDotenvLoadsAFileIntoTheRegistry(): void
    {
        $path = $this->write('.env', "PORT=3000\n");

        Config::load('env', $path, new Dotenv());

        $this->assertSame('3000', Config::getParam('env.PORT'));
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        \file_put_contents($path, $contents);

        return $path;
    }
}
