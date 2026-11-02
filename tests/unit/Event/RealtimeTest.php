<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use Appwrite\Event\Realtime;
use Appwrite\PubSub\Adapter as PubSubAdapter;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Registry\Registry;

require_once __DIR__ . '/../../../app/init.php';

final class RealtimeTest extends TestCase
{
    private PubSubRecorder $recorder;

    private Registry $previousRegister;

    protected function setUp(): void
    {
        // trigger() publishes each copy through the adapter's lazy pubsub pool,
        // resolved from the global registry. Swap the whole registry for a fresh
        // one with a synchronous, in-memory pool so the published copies can be
        // inspected without Redis or Swoole, and restore it in tearDown so later
        // tests keep the process-wide pools (e.g. the lock pool).
        $this->recorder = new PubSubRecorder();

        $pool = new Pool(new Stack(), 'pubsub', 1, fn (): PubSubRecorder => $this->recorder, 1.0);
        $group = new Group();
        $group->add($pool);

        global $register;
        $this->previousRegister = $register;
        $register = new Registry();
        $register->set('pools', fn (): Group => $group);
    }

    protected function tearDown(): void
    {
        global $register;
        $register = $this->previousRegister;
    }

    /**
     * A console-scoped event (e.g. rules.*.create) is published to both the
     * console tree and the originating project tree, carrying the project's
     * console read roles. Those roles mean nothing in the project tree — a
     * colliding project-local team could match them — so the project copy must
     * not be sent.
     */
    public function testConsoleScopedEventIsNotDeliveredToProjectTree(): void
    {
        $realtime = new Realtime();
        $realtime
            ->setProject($this->project())
            ->setSubscribers(['console', 'project1'])
            ->setEvent('rules.[ruleId].create')
            ->setParam('ruleId', 'rule1')
            ->setPayload(['$id' => 'rule1'])
            ->trigger();

        $projectsSentTo = $this->recorder->projectsSentTo();

        $this->assertSame(['console'], $projectsSentTo, 'A console-scoped event must stay in the console tree');
    }

    /**
     * Executions are the inverse: they carry the invoker's user:{id} read role
     * and are published to both trees with no target tree of their own. The
     * project copy keeps user:{id} so the end user still receives it, while the
     * console copy is re-addressed to the project's read roles so it cannot
     * match an unrelated console account with the same id.
     */
    public function testExecutionConsoleCopyIsReaddressedToProjectReadRoles(): void
    {
        $realtime = new Realtime();
        $realtime
            ->setProject($this->project())
            ->setSubscribers(['console', 'project1'])
            ->setEvent('functions.[functionId].executions.[executionId].create')
            ->setParam('functionId', 'func1')
            ->setParam('executionId', 'exec1')
            ->setPayload([
                '$id' => 'exec1',
                'resourceId' => 'func1',
                '$permissions' => [Permission::read(Role::user('user1'))],
            ])
            ->trigger();

        $rolesByProject = $this->recorder->rolesByProject();

        $this->assertArrayHasKey('console', $rolesByProject);
        $this->assertArrayHasKey('project1', $rolesByProject);
        $this->assertSame([Role::team('org1')->toString()], $rolesByProject['console'], 'Console copy carries the project read roles, not user:{id}');
        $this->assertSame([Role::user('user1')->toString()], $rolesByProject['project1'], 'Project copy keeps the execution read role so the end user receives it');
    }

    /**
     * Membership events are addressed to the whole team, and their payload is the response
     * rendered for the *writer* — an API key or admin-mode caller renders the member's real
     * name and email. The project's membership privacy policy must be applied again on the
     * broadcast, with no privileged or self exemption, so a teammate never receives a field
     * the read endpoints hide from them.
     */
    public function testMembershipPayloadDropsFieldsHiddenByPrivacyPolicy(): void
    {
        $realtime = new Realtime();
        $realtime
            ->setProject($this->project([
                'membershipsUserName' => false,
                'membershipsUserEmail' => false,
                'membershipsUserId' => false,
                'membershipsUserPhone' => false,
                'membershipsMfa' => false,
            ]))
            ->setEvent('teams.[teamId].memberships.[membershipId].create')
            ->setParam('teamId', 'team1')
            ->setParam('membershipId', 'membership1')
            ->setPayload([
                '$id' => 'membership1',
                'teamId' => 'team1',
                'teamName' => 'VIP',
                'userId' => 'user1',
                'userName' => 'John Doe',
                'userEmail' => 'john@appwrite.io',
                'userPhone' => '+15555555555',
                'mfa' => true,
                'roles' => ['member'],
            ])
            ->trigger();

        $payload = $this->recorder->payloads()[0] ?? [];

        $this->assertArrayNotHasKey('userName', $payload, 'A hidden user name must not reach the team');
        $this->assertArrayNotHasKey('userEmail', $payload, 'A hidden user email must not reach the team');
        $this->assertArrayNotHasKey('userId', $payload, 'A hidden user id must not reach the team');
        $this->assertArrayNotHasKey('userPhone', $payload, 'A hidden user phone must not reach the team');
        $this->assertArrayNotHasKey('mfa', $payload, 'A hidden MFA status must not reach the team');
        $this->assertSame('membership1', $payload['$id'] ?? null, 'The membership itself still reaches the team');
        $this->assertSame('VIP', $payload['teamName'] ?? null, 'Fields the policy does not cover are untouched');
        $this->assertSame(['member'], $payload['roles'] ?? null, 'Fields the policy does not cover are untouched');
    }

    /**
     * The policy is opt-in: a project that allows the fields keeps broadcasting them.
     */
    public function testMembershipPayloadKeepsFieldsAllowedByPrivacyPolicy(): void
    {
        $realtime = new Realtime();
        $realtime
            ->setProject($this->project([
                'membershipsUserName' => true,
                'membershipsUserEmail' => true,
                'membershipsUserId' => true,
            ]))
            ->setEvent('teams.[teamId].memberships.[membershipId].update')
            ->setParam('teamId', 'team1')
            ->setParam('membershipId', 'membership1')
            ->setPayload([
                '$id' => 'membership1',
                'userId' => 'user1',
                'userName' => 'John Doe',
                'userEmail' => 'john@appwrite.io',
            ])
            ->trigger();

        $payload = $this->recorder->payloads()[0] ?? [];

        $this->assertSame('user1', $payload['userId'] ?? null);
        $this->assertSame('John Doe', $payload['userName'] ?? null);
        $this->assertSame('john@appwrite.io', $payload['userEmail'] ?? null);
    }

    /**
     * @param array<string, mixed> $auths
     */
    private function project(array $auths = []): Document
    {
        return new Document([
            '$id' => 'project1',
            '$permissions' => [Permission::read(Role::team('org1'))],
            'auths' => $auths,
        ]);
    }
}

/**
 * Records the pubsub messages trigger() publishes, keyed later by target project.
 */
final class PubSubRecorder implements PubSubAdapter
{
    /** @var array<int, array<string, mixed>> */
    private array $messages = [];

    public function ping($message = null): bool
    {
        return true;
    }

    public function subscribe($channels, $callback): void
    {
    }

    public function publish($channel, $message): void
    {
        $decoded = \json_decode((string) $message, true);
        if (\is_array($decoded)) {
            $this->messages[] = $decoded;
        }
    }

    /**
     * @return array<int, string>
     */
    public function projectsSentTo(): array
    {
        return \array_map(fn (array $m): string => (string) ($m['project'] ?? ''), $this->messages);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function payloads(): array
    {
        return \array_map(fn (array $m): array => $m['data']['payload'] ?? [], $this->messages);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rolesByProject(): array
    {
        $roles = [];
        foreach ($this->messages as $message) {
            $roles[(string) ($message['project'] ?? '')] = $message['roles'] ?? [];
        }

        return $roles;
    }
}
