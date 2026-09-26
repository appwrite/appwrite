<?php

declare(strict_types=1);

namespace Utopia\Http;

use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function setUp(): void
    {
        Router::setAllowOverride(false);
    }

    public function tearDown(): void
    {
        Router::reset();
    }

    public function testCanMatchUrl(): void
    {
        $routeIndex = new Route(Http::REQUEST_METHOD_GET, '/');
        $routeAbout = new Route(Http::REQUEST_METHOD_GET, '/about');
        $routeAboutMe = new Route(Http::REQUEST_METHOD_GET, '/about/me');

        Router::addRoute($routeIndex);
        Router::addRoute($routeAbout);
        Router::addRoute($routeAboutMe);

        $this->assertEquals($routeIndex, Router::match(Http::REQUEST_METHOD_GET, '/')?->route);
        $this->assertEquals($routeAbout, Router::match(Http::REQUEST_METHOD_GET, '/about')?->route);
        $this->assertEquals($routeAboutMe, Router::match(Http::REQUEST_METHOD_GET, '/about/me')?->route);
    }

    public function testCanMatchUrlWithPlaceholder(): void
    {
        $routeBlog = new Route(Http::REQUEST_METHOD_GET, '/blog');
        $routeBlogAuthors = new Route(Http::REQUEST_METHOD_GET, '/blog/authors');
        $routeBlogAuthorsComments = new Route(Http::REQUEST_METHOD_GET, '/blog/authors/comments');
        $routeBlogPost = new Route(Http::REQUEST_METHOD_GET, '/blog/:post');
        $routeBlogPostComments = new Route(Http::REQUEST_METHOD_GET, '/blog/:post/comments');
        $routeBlogPostCommentsSingle = new Route(Http::REQUEST_METHOD_GET, '/blog/:post/comments/:comment');

        Router::addRoute($routeBlog);
        Router::addRoute($routeBlogAuthors);
        Router::addRoute($routeBlogAuthorsComments);
        Router::addRoute($routeBlogPost);
        Router::addRoute($routeBlogPostComments);
        Router::addRoute($routeBlogPostCommentsSingle);

        $this->assertEquals($routeBlog, Router::match(Http::REQUEST_METHOD_GET, '/blog')?->route);
        $this->assertEquals($routeBlogAuthors, Router::match(Http::REQUEST_METHOD_GET, '/blog/authors')?->route);
        $this->assertEquals($routeBlogAuthorsComments, Router::match(Http::REQUEST_METHOD_GET, '/blog/authors/comments')?->route);
        $this->assertEquals($routeBlogPost, Router::match(Http::REQUEST_METHOD_GET, '/blog/test')?->route);
        $this->assertEquals($routeBlogPostComments, Router::match(Http::REQUEST_METHOD_GET, '/blog/test/comments')?->route);
        $this->assertEquals($routeBlogPostCommentsSingle, Router::match(Http::REQUEST_METHOD_GET, '/blog/test/comments/:comment')?->route);
    }

    public function testCanMatchUrlWithWildcard(): void
    {
        $routeIndex = new Route('GET', '/');
        $routeAbout = new Route('GET', '/about');
        $routeAboutWildcard = new Route('GET', '/about/*');

        Router::addRoute($routeIndex);
        Router::addRoute($routeAbout);
        Router::addRoute($routeAboutWildcard);

        $this->assertEquals($routeIndex, Router::match('GET', '/')?->route);
        $this->assertEquals($routeAbout, Router::match('GET', '/about')?->route);
        $this->assertEquals($routeAboutWildcard, Router::match('GET', '/about/me')?->route);
        $this->assertEquals($routeAboutWildcard, Router::match('GET', '/about/you')?->route);
        $this->assertEquals($routeAboutWildcard, Router::match('GET', '/about/me/myself/i')?->route);
    }

    public function testCanMatchHttpMethod(): void
    {
        $routeGET = new Route(Http::REQUEST_METHOD_GET, '/');
        $routePOST = new Route(Http::REQUEST_METHOD_POST, '/');

        Router::addRoute($routeGET);
        Router::addRoute($routePOST);

        $this->assertSame($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/')?->route);
        $this->assertSame($routePOST, Router::match(Http::REQUEST_METHOD_POST, '/')?->route);

        $this->assertNotSame($routeGET, Router::match(Http::REQUEST_METHOD_POST, '/')->route);
        $this->assertNotSame($routePOST, Router::match(Http::REQUEST_METHOD_GET, '/')->route);
    }

    public function testCanMatchAlias(): void
    {
        $routeGET = new Route(Http::REQUEST_METHOD_GET, '/target');
        $routeGET
            ->alias('/alias')
            ->alias('/alias2');

        Router::addRoute($routeGET);

        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/target')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/alias')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/alias2')?->route);
    }

    public function testCanMatchMultipleAliases(): void
    {
        $routeGET = new Route(Http::REQUEST_METHOD_GET, '/target');
        $routeGET
            ->alias('/alias1')
            ->alias('/alias2')
            ->alias('/alias3');

        Router::addRoute($routeGET);

        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/target')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/alias1')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/alias2')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/alias3')?->route);
    }

    public function testCanMatchMix(): void
    {
        $routeGET = new Route(Http::REQUEST_METHOD_GET, '/');
        $routeGET
            ->alias('/console/*')
            ->alias('/auth/*')
            ->alias('/invite')
            ->alias('/login')
            ->alias('/recover')
            ->alias('/register/*');

        Router::addRoute($routeGET);

        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/console')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/invite')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/login')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/recover')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/console/lorem/ipsum/dolor')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/auth/lorem/ipsum')?->route);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/register/lorem/ipsum')?->route);
    }

    public function testCanMatchRouteWithMultipleMethods(): void
    {
        $route = Http::routes([Http::REQUEST_METHOD_GET, Http::REQUEST_METHOD_POST], '/userinfo');

        $this->assertEquals($route, Router::match(Http::REQUEST_METHOD_GET, '/userinfo')?->route);
        $this->assertEquals($route, Router::match(Http::REQUEST_METHOD_POST, '/userinfo')?->route);
        $this->assertNull(Router::match(Http::REQUEST_METHOD_PUT, '/userinfo'));

        $this->assertSame([Http::REQUEST_METHOD_GET, Http::REQUEST_METHOD_POST], $route->getMethods());
    }

    public function testCanMatchRouteWithStringMethod(): void
    {
        $route = Http::routes(Http::REQUEST_METHOD_GET, '/userinfo');

        $this->assertEquals($route, Router::match(Http::REQUEST_METHOD_GET, '/userinfo')?->route);
        $this->assertNull(Router::match(Http::REQUEST_METHOD_POST, '/userinfo'));
    }

    public function testCanMatchRouteWithMultipleMethodsAndPlaceholder(): void
    {
        $route = Http::routes([Http::REQUEST_METHOD_GET, Http::REQUEST_METHOD_POST], '/users/:id');

        $match = Router::match(Http::REQUEST_METHOD_POST, '/users/abc-123');

        $this->assertEquals($route, $match?->route);
        $this->assertSame(['id' => 'abc-123'], $match?->params);
    }

    public function testRoutesCrossPathAliases(): void
    {
        $route = Http::routes([Http::REQUEST_METHOD_GET, Http::REQUEST_METHOD_POST], '/a')
            ->alias('/a-old');

        $this->assertEquals($route, Router::match(Http::REQUEST_METHOD_GET, '/a')?->route);
        $this->assertEquals($route, Router::match(Http::REQUEST_METHOD_POST, '/a')?->route);
        $this->assertEquals($route, Router::match(Http::REQUEST_METHOD_GET, '/a-old')?->route);
        $this->assertEquals($route, Router::match(Http::REQUEST_METHOD_POST, '/a-old')?->route);

        $routePOST = Http::routes(Http::REQUEST_METHOD_POST, '/b')->alias('/b-old');
        $routeGETPOST = Http::routes([Http::REQUEST_METHOD_GET, Http::REQUEST_METHOD_POST], '/c');

        try {
            $routeGETPOST->alias('/b-old');
            $this->fail('Expected duplicate route alias exception.');
        } catch (\Exception $exception) {
            $this->assertSame('Route for (POST:b-old) already registered.', $exception->getMessage());
        }

        $this->assertNull(Router::match(Http::REQUEST_METHOD_GET, '/b-old'));
        $this->assertEquals($routePOST, Router::match(Http::REQUEST_METHOD_POST, '/b-old')?->route);
    }

    public function testCannotRegisterDuplicateRouteMethod(): void
    {
        $routePOST = new Route(Http::REQUEST_METHOD_POST, '/userinfo');
        Router::addRoute($routePOST);

        try {
            Http::routes([Http::REQUEST_METHOD_GET, Http::REQUEST_METHOD_POST], '/userinfo');
            $this->fail('Expected duplicate route exception.');
        } catch (\Exception $exception) {
            $this->assertSame('Route for (POST:userinfo) already registered.', $exception->getMessage());
        }

        $routes = Router::getRoutes();
        $this->assertArrayNotHasKey('userinfo', $routes[Http::REQUEST_METHOD_GET]);

        $routeGET = Http::routes(Http::REQUEST_METHOD_GET, '/userinfo');

        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/userinfo')?->route);
        $this->assertEquals($routePOST, Router::match(Http::REQUEST_METHOD_POST, '/userinfo')?->route);
    }

    public function testCanOverrideRouteMethod(): void
    {
        Router::setAllowOverride(true);

        try {
            $routePOST = new Route(Http::REQUEST_METHOD_POST, '/userinfo');
            Router::addRoute($routePOST);

            $routeGET = Http::routes([
                Http::REQUEST_METHOD_GET,
                Http::REQUEST_METHOD_POST,
                Http::REQUEST_METHOD_POST,
            ], '/userinfo');

            $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_POST, '/userinfo')?->route);
        } finally {
            Router::setAllowOverride(false);
        }
    }

    public function testCannotRegisterRouteForUnknownMethod(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Method (TRACE) not supported.');
        Http::routes([Http::REQUEST_METHOD_GET, 'TRACE'], '/userinfo');
    }

    public function testUnknownMethodDoesNotPartiallyRegisterRoute(): void
    {
        try {
            Http::routes([Http::REQUEST_METHOD_GET, 'TRACE'], '/userinfo');
            $this->fail('Expected unknown method exception.');
        } catch (\Exception $exception) {
            $this->assertSame('Method (TRACE) not supported.', $exception->getMessage());
        }

        $routes = Router::getRoutes();
        $this->assertArrayNotHasKey('userinfo', $routes[Http::REQUEST_METHOD_GET]);

        $route = Http::routes([Http::REQUEST_METHOD_GET, Http::REQUEST_METHOD_POST], '/userinfo');

        $this->assertEquals($route, Router::match(Http::REQUEST_METHOD_GET, '/userinfo')?->route);
        $this->assertEquals($route, Router::match(Http::REQUEST_METHOD_POST, '/userinfo')?->route);
    }

    public function testCannotRegisterRouteWithoutMethods(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('At least one HTTP method is required.');
        Http::routes([], '/userinfo');
    }

    public function testCanMatchFilename(): void
    {
        $routeGET = new Route(Http::REQUEST_METHOD_GET, '/robots.txt');

        Router::addRoute($routeGET);
        $this->assertEquals($routeGET, Router::match(Http::REQUEST_METHOD_GET, '/robots.txt')?->route);
    }

    public function testCannotFindUnknownRouteByPath(): void
    {
        $this->assertNull(Router::match(Http::REQUEST_METHOD_GET, '/404')?->route);
    }

    public function testCannotFindUnknownRouteByMethod(): void
    {
        $route = new Route(Http::REQUEST_METHOD_GET, '/404');

        Router::addRoute($route);

        $this->assertEquals($route, Router::match(Http::REQUEST_METHOD_GET, '/404')?->route);

        $this->assertNull(Router::match(Http::REQUEST_METHOD_POST, '/404')?->route);
    }
}
