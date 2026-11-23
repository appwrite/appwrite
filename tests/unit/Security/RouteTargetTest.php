<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Tests\E2E\Security\Catalog;
use Tests\E2E\Security\RouteTarget;
use Utopia\Http\Route;
use Utopia\Validator\Text;
use Utopia\Validator\URL;

final class RouteTargetTest extends TestCase
{
    public function testGuestAndScopeIntersection(): void
    {
        $route = new RouteTarget('GET', '/v1/locale', ['api'], ['locale.read'], [], true, false, true);

        $this->assertSame('GET /v1/locale', $route->id());
        $this->assertTrue($route->allowsGuest(['locale.read', 'public']));
        $this->assertFalse($route->allowsGuest(['users.read']));
        $this->assertTrue($route->allowsScopes(['locale.read']));
        $this->assertFalse($route->hasAnyIdParam());
    }

    public function testIdAndUrlParams(): void
    {
        $route = new RouteTarget(
            method: 'GET',
            path: '/v1/users/:userId/sessions/:sessionId',
            groups: ['api'],
            scopes: ['users.read'],
            params: [
                'url' => ['validator' => new URL(), 'description' => 'Image URL', 'optional' => false],
                'name' => ['validator' => new Text(128), 'description' => 'Name', 'optional' => false],
            ],
            docs: true,
            mock: false,
            inApiGroup: true,
        );

        $this->assertTrue($route->hasPathParam('userId'));
        $this->assertTrue($route->hasAnyIdParam());
        $this->assertSame(['url'], $route->urlParamNames());
    }

    public function testCatalogDropsMocksAliasesAndNonApi(): void
    {
        $users = new Route('GET', '/v1/users/:userId');
        $users->groups(['api'])->label('scope', 'users.read');

        $alias = new Route('GET', '/v1/users/:userId');
        $alias->groups(['api'])->label('scope', 'users.read');

        $graphql = new Route('POST', '/v1/graphql');
        $graphql->groups(['graphql'])->label('scope', 'graphql');

        $mock = new Route('GET', '/v1/mock/tests');
        $mock->groups(['api'])->label('scope', 'public')->label('mock', true);

        $web = new Route('GET', '/robots.txt');
        $web->groups(['web'])->label('scope', 'public');

        $targets = Catalog::fromRouter([
            'GET' => [
                'prepared-users' => $users,
                'prepared-users-alias' => $alias,
                'prepared-mock' => $mock,
                'prepared-web' => $web,
            ],
            'POST' => [
                'prepared-graphql' => $graphql,
            ],
        ]);

        $ids = Catalog::ids($targets);
        $this->assertSame(['GET /v1/users/:userId'], $ids);
    }
}
