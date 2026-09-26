<?php

declare(strict_types=1);

namespace Utopia\Http;

use PHPUnit\Framework\TestCase;
use Utopia\Validator\Text;

final class RouteTest extends TestCase
{
    protected ?Route $route;

    public function setUp(): void
    {
        $this->route = new Route('GET', '/');
    }

    public function testCanGetMethod(): void
    {
        $this->assertSame('GET', $this->route->getMethod());
        $this->assertSame(['GET'], $this->route->getMethods());
    }

    public function testCanGetAndSetPath(): void
    {
        $this->assertSame('/', $this->route->getPath());

        $this->route->path('/path');

        $this->assertSame('/path', $this->route->getPath());
    }

    public function testCanSetAndGetDescription(): void
    {
        $this->assertSame('', $this->route->getDesc());

        $this->route->desc('new route');

        $this->assertSame('new route', $this->route->getDesc());
    }

    public function testCanSetAndGetGroups(): void
    {
        $this->assertSame([], $this->route->getGroups());

        $this->route->groups(['api', 'homepage']);

        $this->assertSame(['api', 'homepage'], $this->route->getGroups());
    }

    public function testCanSetAndGetAction(): void
    {
        $this->assertInstanceOf(\Closure::class, $this->route->getAction());

        $this->route->action(fn() => 'hello world');

        $this->assertSame('hello world', $this->route->getAction()());
    }

    public function testCanGetAndSetParam(): void
    {
        $this->assertSame([], $this->route->getParams());

        $this->route
            ->param('x', '', new Text(10))
            ->param('y', '', new Text(10));

        $this->assertCount(2, $this->route->getParams());
    }

    public function testCanInjectResources(): void
    {
        $this->assertSame([], $this->route->getInjections());

        $this->route
            ->inject('user')
            ->inject('time')
            ->action(function () {});

        $this->assertCount(2, $this->route->getInjections());
        $this->assertSame('user', $this->route->getInjections()['user']['name']);
        $this->assertSame('time', $this->route->getInjections()['time']['name']);
    }

    public function testCanSetAndGetLabels(): void
    {
        $this->assertSame('default', $this->route->getLabel('key', 'default'));

        $this->route->label('key', 'value');

        $this->assertSame('value', $this->route->getLabel('key', 'default'));
    }

    public function testCanSetAndGetHooks(): void
    {
        $this->assertTrue($this->route->getHook());
        $this->route->hook(true);
        $this->assertTrue($this->route->getHook());
        $this->route->hook(false);
        $this->assertFalse($this->route->getHook());
    }

    public function tearDown(): void
    {
        $this->route = null;
    }
}
