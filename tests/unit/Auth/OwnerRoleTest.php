<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Appwrite\Auth\OwnerRole;
use PHPUnit\Framework\TestCase;

final class OwnerRoleTest extends TestCase
{
    public function testOrganizationKeyCannotAssignOwner(): void
    {
        // An organization API key reaches the membership guard as an app user but without the
        // genuine owner role. It must not escalate a membership to owner on the console.
        $this->assertFalse(OwnerRole::mayAssign(['owner'], 'console', false));
        $this->assertFalse(OwnerRole::mayAssign(['editor', 'owner'], 'console', false));
    }

    public function testGenuineOwnerCanAssignOwner(): void
    {
        $this->assertTrue(OwnerRole::mayAssign(['owner'], 'console', true));
    }

    public function testNonOwnerRolesAreUnaffectedOnConsole(): void
    {
        // Managing members with non-owner roles stays available to an organization key.
        $this->assertTrue(OwnerRole::mayAssign(['editor'], 'console', false));
        $this->assertTrue(OwnerRole::mayAssign([], 'console', false));
    }

    public function testOwnerAssignmentOutsideConsoleIsLeftToTheEndpoint(): void
    {
        // Project API keys legitimately manage team roles, including owner, within their own
        // project; this rule only governs organizations on the console project.
        $this->assertTrue(OwnerRole::mayAssign(['owner'], 'myproject', false));
        $this->assertTrue(OwnerRole::mayAssign(['owner'], 'myproject', true));
    }
}
