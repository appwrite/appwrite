<?php

declare(strict_types=1);

namespace Utopia\Config\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

final class RegistryTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $params;

    protected function setUp(): void
    {
        $this->params = Config::$params;
        Config::$params = [];
    }

    protected function tearDown(): void
    {
        Config::$params = $this->params;
    }

    public function testGetParamResolvesFlatAndDottedKeys(): void
    {
        Config::setParam('key', 'value');
        Config::setParam('nested', ['child' => ['leaf' => 'leafValue'], 'zero' => 0]);

        $this->assertSame('value', Config::getParam('key'));
        $this->assertSame('leafValue', Config::getParam('nested.child.leaf'));
        $this->assertSame(['leaf' => 'leafValue'], Config::getParam('nested.child'));
        $this->assertSame(0, Config::getParam('nested.zero'));
    }

    public function testGetParamFallsBackToTheDefault(): void
    {
        Config::setParam('nested', ['child' => 'value', 'empty' => null]);
        Config::setParam('null', null);

        $this->assertNull(Config::getParam('missing'));
        $this->assertSame('default', Config::getParam('missing', 'default'));
        $this->assertSame('default', Config::getParam('null', 'default'));
        $this->assertSame('default', Config::getParam('nested.missing', 'default'));
        $this->assertSame('default', Config::getParam('nested.empty', 'default'));
        $this->assertSame('default', Config::getParam('nested.child.deeper', 'default'));
    }

    public function testSetParamOverwrites(): void
    {
        Config::setParam('key', 'first');
        Config::setParam('key', 'second');

        $this->assertSame('second', Config::getParam('key'));
    }
}
