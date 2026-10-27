<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use Appwrite\Event\Realtime;
use Appwrite\Messaging\Adapter\Realtime as RealtimeAdapter;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

require_once __DIR__ . '/../../../app/init.php';

/**
 * Exposes the subscriber role resolution for assertion.
 */
final class FakeRealtime extends Realtime
{
    public function resolveRoles(string $subscriber, array $roles, ?string $targetProjectId): ?array
    {
        return $this->resolveSubscriberRoles($subscriber, $roles, $targetProjectId);
    }
}

final class RealtimeTest extends TestCase
{
    private FakeRealtime $event;

    public function setUp(): void
    {
        $this->event = new FakeRealtime();
        $this->event->setProject(new Document([
            '$id' => 'project-a',
            'teamId' => 'org-team',
        ]));
    }

    public function testConsoleCopyOfProjectEventIsAddressedToPrivilegedRoles(): void
    {
        // An execution event's roles are the execution's read permissions:
        // project-user identities. On the console tree `user:{id}` names a
        // console user instead, which anyone can mint with a colliding custom
        // ID. The console copy is addressed to the org's privileged roles —
        // exactly who bypasses the per-execution permission filter in admin
        // mode — not the bare team role every member holds.
        $roles = $this->event->resolveRoles('console', ['user:victim'], null);

        $this->assertSame([
            'team:org-team/owner',
            'team:org-team/developer',
            'team:org-team/admin',
        ], $roles);
    }

    public function testConsoleCopyIsSkippedWithoutOrganization(): void
    {
        $this->event->setProject(new Document(['$id' => 'project-a']));

        $this->assertNull($this->event->resolveRoles('console', ['user:victim'], null));
    }

    public function testProjectCopyOfProjectEventKeepsItsRoles(): void
    {
        $roles = $this->event->resolveRoles('project-a', ['user:victim', 'users'], null);

        $this->assertSame(['user:victim', 'users'], $roles);
    }

    public function testProjectCopyOfConsoleEventIsSkipped(): void
    {
        // Rules events are keyed `team:{orgTeamId}`; in the project tree that
        // names a project team anyone can mint with a colliding custom ID.
        $this->assertNull($this->event->resolveRoles('project-a', ['team:org-team'], 'console'));
    }

    public function testConsoleCopyOfConsoleEventKeepsItsRoles(): void
    {
        $roles = $this->event->resolveRoles('console', ['team:org-team'], 'console');

        $this->assertSame(['team:org-team'], $roles);
    }

    public function testConsoleProjectEventsAreUntouched(): void
    {
        $this->event->setProject(new Document(['$id' => 'console']));

        $roles = $this->event->resolveRoles('console', ['user:console-user'], null);

        $this->assertSame(['user:console-user'], $roles);
    }

    public function testConsoleCopyOfPrivilegedExecutionGetsPrivilegedRoles(): void
    {
        // An admin- or key-invoked execution carries no read role; the console
        // copy must still reach the org's privileged members.
        $roles = $this->event->resolveRoles('console', [], null);

        $this->assertSame([
            'team:org-team/owner',
            'team:org-team/developer',
            'team:org-team/admin',
        ], $roles);
    }

    public function testProjectCopyOfPrivilegedExecutionReachesNoOne(): void
    {
        // No read role → the project copy matches nothing, so a colliding
        // project user cannot receive an admin-invoked execution.
        $this->assertSame([], $this->event->resolveRoles('project-a', [], null));
    }

    public function testExecutionEventKeepsConsoleChannelWithoutReadPermissions(): void
    {
        // Without this, fromPayload dropped the whole event when the execution
        // had no read role, so the console lost live updates for admin- and
        // key-invoked executions.
        $target = RealtimeAdapter::fromPayload(
            'functions.fn1.executions.e1.update',
            new Document(['$id' => 'e1', 'resourceId' => 'fn1', '$permissions' => []]),
            new Document(['$id' => 'project-a', 'teamId' => 'org-team']),
        );

        $this->assertContains('console', $target['channels']);
        $this->assertContains('executions', $target['channels']);
        $this->assertSame([], $target['roles']);
    }
}
