<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Tests\E2E\Client;
use Tests\E2E\Security\Probe;
use Tests\E2E\Security\RouteTarget;

final class ProbeTest extends TestCase
{
    public function testFillPathAndClientPrefix(): void
    {
        $probe = new Probe(new Client());

        $this->assertSame(
            '/v1/users/victim/sessions/sessA',
            $probe->fillPath('/v1/users/:userId/sessions/:sessionId', [
                'userId' => 'victim',
                'sessionId' => 'sessA',
            ])
        );
        $this->assertSame(
            '/v1/users/secPlaceholder00000000000001',
            $probe->fillPath('/v1/users/:userId', [])
        );
        $this->assertSame('/account', $probe->toClientPath('/v1/account'));
        $this->assertSame('/', $probe->toClientPath('/v1'));
    }

    public function testDeniedAndMissingRoute(): void
    {
        $probe = new Probe(new Client());
        $route = new RouteTarget('GET', '/v1/users', ['api'], ['users.read'], [], true, false, true);

        $this->assertTrue($probe->isDenied(['headers' => ['status-code' => 401], 'body' => []]));
        $this->assertTrue($probe->isMissingRoute([
            'headers' => ['status-code' => 404],
            'body' => ['type' => 'general_route_not_found'],
        ]));
        $this->assertFalse($probe->isSuccess(['headers' => ['status-code' => 404], 'body' => []]));
        $this->assertSame(0, $probe->status(['headers' => []]));
        $this->assertSame('GET', $route->method);
    }
}
