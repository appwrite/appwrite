<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Tests\E2E\Security\Attack;
use Tests\E2E\Security\Attacks;
use Tests\E2E\Security\RouteTarget;

final class AttacksTest extends TestCase
{
    public function testRegistryLoadsEveryClass(): void
    {
        $attacks = Attacks::all();
        $names = \array_map(static fn (Attack $attack): string => $attack::getName(), $attacks);

        $this->assertCount(\count(\array_unique($names)), $names);
        $this->assertContains('guest-access', $names);
        $this->assertContains('cross-tenant', $names);
        $this->assertContains('idor', $names);
        $this->assertContains('scope-least-privilege', $names);
        $this->assertContains('console-role', $names);
        $this->assertContains('header-spoof', $names);
        $this->assertContains('ssrf-url', $names);
        $this->assertContains('sensitive-fields', $names);
        $this->assertSame('guest-access', Attacks::named('guest-access')::getName());
    }

    public function testApplicabilityIsNarrowWhereItShouldBe(): void
    {
        $locale = new RouteTarget('GET', '/v1/locale', ['api'], ['locale.read'], [], true, false, true);
        $users = new RouteTarget('GET', '/v1/users/:userId', ['api'], ['users.read'], [], true, false, true);
        $image = new RouteTarget('GET', '/v1/avatars/image', ['api'], ['avatars.read'], [
            'url' => ['validator' => null, 'description' => 'Image URL', 'optional' => false],
        ], true, false, true);

        $byName = [];
        foreach (Attacks::all() as $attack) {
            $byName[$attack::getName()] = $attack;
        }

        $this->assertTrue($byName['guest-access']->applies($users));
        $this->assertFalse($byName['idor']->applies($locale));
        $this->assertTrue($byName['idor']->applies($users));
        $this->assertFalse($byName['ssrf-url']->applies($users));
        $this->assertTrue($byName['ssrf-url']->applies($image));
        $this->assertFalse($byName['scope-least-privilege']->applies($locale));
        $this->assertFalse($byName['cross-tenant']->applies($locale));
        $this->assertTrue($byName['scope-least-privilege']->applies($users));
        $this->assertTrue($byName['cross-tenant']->applies($users));
        $this->assertTrue($byName['sensitive-fields']->applies($locale));
    }
}
