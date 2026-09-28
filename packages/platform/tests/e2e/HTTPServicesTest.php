<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\DI\Container;
use Utopia\Http\Adapter\FPM\Request;
use Utopia\Http\Adapter\FPM\Server;
use Utopia\Http\Http;

final class HttpServicesTest extends TestCase
{
    protected ?string $method = null;

    protected ?string $uri = null;

    protected ?Http $http;

    public function setUp(): void
    {
        Http::reset();
        $platform = new TestPlatform();
        $platform->init('http');

        $server = new Server(new Container());
        $this->http = new Http($server, 'UTC');
    }

    public function tearDown(): void
    {
        $this->http = null;
    }

    public function testRootAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = new Request();
        $response = new MockResponse();

        ob_start();
        $this->http->run($request, $response);
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertSame('Hello World!', $result);
    }

    public function testChunkedAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/chunked';

        $request = new Request();
        $response = new MockResponse();

        ob_start();
        $this->http->run($request, $response);
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertSame('Hello World!', $result);
    }

    public function testRedirectAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/redirect';

        $request = new Request();
        $response = new MockResponse();

        $this->http->run($request, $response);

        $this->assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testHook(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = new Request();
        $response = new MockResponse();

        ob_start();
        $this->http->run($request, $response);
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertSame('Hello World!', $result);
        $this->assertSame('init-called', $response->getHeaderLine('x-init'));

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/chunked';

        $request1 = new Request();
        $response1 = new MockResponse();

        ob_start();
        $this->http->run($request1, $response1);
        $result = ob_get_contents();
        ob_end_clean();

        $this->assertSame('Hello World!', $result);
        $this->assertSame('', $response1->getHeaderLine('x-init'));
    }

    public function testAliasedAction(): void
    {
        $paths = ['/aliased', '/alias-one', '/alias-two', '/alias-three'];

        foreach ($paths as $path) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = $path;

            $request = new Request();
            $response = new MockResponse();

            ob_start();
            $this->http->run($request, $response);
            $result = ob_get_contents();
            ob_end_clean();

            $this->assertSame('Aliased!', $result, "Alias '{$path}' should resolve to the aliased action");
        }
    }

    public function testActionParamFieldsForwardedToRoute(): void
    {
        $routes = Http::getRoutes();

        $route = null;
        foreach ($routes as $methodRoutes) {
            foreach ($methodRoutes as $r) {
                if ($r->getPath() === '/with-params') {
                    $route = $r;
                    break 2;
                }
            }
        }

        $this->assertInstanceOf(\Utopia\Http\Route::class, $route, 'Route /with-params should be registered');

        $params = $route->getParams();

        // Verify all Action::param() fields are forwarded to the Route
        $actionParamKeys = ['default', 'validator', 'description', 'optional', 'injections', 'skipValidation', 'deprecated', 'example', 'aliases'];

        foreach ($params as $name => $param) {
            foreach ($actionParamKeys as $key) {
                $this->assertArrayHasKey($key, $param, "Param '{$name}' is missing '{$key}' on the Route. Platform must forward all Action param fields.");
            }
        }

        $this->assertEquals('John Doe', $params['name']['example']);
        $this->assertFalse($params['name']['deprecated']);
        $this->assertEquals('true', $params['active']['example']);
        $this->assertTrue($params['active']['deprecated']);

        // Verify aliases are forwarded
        $this->assertArrayHasKey('email', $params, 'Param email should be registered');
        $this->assertArrayHasKey('aliases', $params['email'], 'Param email should have aliases key');
        $this->assertEquals(['emailAddress', 'userEmail'], $params['email']['aliases']);

        // Verify params without aliases have empty array
        $this->assertArrayHasKey('aliases', $params['name'], 'Param name should have aliases key');
        $this->assertEquals([], $params['name']['aliases']);

        // Verify enum is forwarded to Route params
        $this->assertArrayHasKey('enum', $params['status'], 'Param status should have enum key on Route');
        $this->assertInstanceOf(\Utopia\Platform\Enum::class, $params['status']['enum']);
        $this->assertSame('ArticleStatus', $params['status']['enum']->name);
        $this->assertSame(['draft' => 'Draft', 'published' => 'Published'], $params['status']['enum']->map);

        // Verify enum is stored on Action params
        $action = new TestActionWithParams();
        $actionParams = $action->getParams();

        $this->assertInstanceOf(\Utopia\Platform\Enum::class, $actionParams['status']['enum']);
        $this->assertSame('ArticleStatus', $actionParams['status']['enum']->name);
        $this->assertSame(['draft' => 'Draft', 'published' => 'Published'], $actionParams['status']['enum']->map);

        // Verify params without enum are null on Action
        $this->assertNull($actionParams['name']['enum']);
    }
}
