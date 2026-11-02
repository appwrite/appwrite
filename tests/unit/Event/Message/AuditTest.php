<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Event\Message\Audit;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class AuditTest extends TestCase
{
    private const SECRET_USER_FIELDS = [
        'password',
        'hash',
        'hashOptions',
        'mfa',
        'mfaRecoveryCodes',
        'authenticators',
        'secret',
        'sessions',
        'tokens',
        'memberships',
        'targets',
        'prefs',
    ];

    private function storedUser(string $id, string $sequence, string $name, string $email): Document
    {
        return new Document([
            '$id' => $id,
            '$sequence' => $sequence,
            '$createdAt' => '2026-09-03T10:00:00.000+00:00',
            'name' => $name,
            'email' => $email,
            'type' => 'user',
            'phone' => '+15555550100',
            'status' => true,
            'password' => '$argon2id$v=19$m=65536,t=4,p=3$c29tZXNhbHQ$aGFzaGVkcGFzc3dvcmQ',
            'hash' => 'argon2',
            'hashOptions' => ['type' => 'argon2', 'memoryCost' => 65536, 'timeCost' => 4, 'threads' => 3],
            'mfa' => true,
            'mfaRecoveryCodes' => ['a1b2c3d4e5', 'f6g7h8i9j0'],
            'authenticators' => [['type' => 'totp', 'data' => ['secret' => 'JBSWY3DPEHPK3PXP']]],
            'secret' => 'otp-secret',
            'sessions' => [['$id' => 'session-id', 'secret' => 'session-secret', 'providerAccessToken' => 'oauth-token']],
            'tokens' => [['$id' => 'token-id', 'secret' => 'token-secret']],
            'memberships' => [['$id' => 'membership-id', 'teamId' => 'team-id']],
            'targets' => [['$id' => 'target-id', 'identifier' => $email]],
            'prefs' => ['theme' => 'dark'],
        ]);
    }

    private function message(): Audit
    {
        $context = new AuditContext(
            project: new Document(['$id' => 'project-id', '$sequence' => '42', 'teamId' => 'team-id', 'teamInternalId' => '84']),
            user: $this->storedUser('user-id', '7', 'Jane', 'jane@example.test'),
            impersonatorUser: $this->storedUser('admin-id', '3', 'Admin', 'admin@example.test'),
            mode: 'admin',
            userAgent: 'Mozilla/5.0',
            ip: '203.0.113.10',
            hostname: 'cloud.appwrite.io',
            sdk: 'web',
            sdkVersion: '18.0.0',
            event: 'user.update',
            resource: 'user/user-id',
            payload: ['$id' => 'user-id', 'password' => 'response-body', 'secret' => 'response-secret'],
        );

        return Audit::fromContext($context);
    }

    public function testSerializesOnlyTheUserFieldsWorkersRead(): void
    {
        $data = $this->message()->toArray();

        $this->assertSame([
            '$id' => 'user-id',
            '$sequence' => '7',
            'name' => 'Jane',
            'email' => 'jane@example.test',
            'type' => 'user',
        ], $data['user']);

        $this->assertSame([
            '$id' => 'admin-id',
            '$sequence' => '3',
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'type' => 'user',
        ], $data['impersonatorUser']);

        foreach (self::SECRET_USER_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $data['user'], "user.{$field} must not be queued");
            $this->assertArrayNotHasKey($field, $data['impersonatorUser'], "impersonatorUser.{$field} must not be queued");
        }
    }

    public function testDoesNotQueueTheResponseBody(): void
    {
        $data = $this->message()->toArray();

        $this->assertArrayNotHasKey('payload', $data);

        $encoded = \json_encode($data, JSON_THROW_ON_ERROR);
        foreach (['response-body', 'response-secret', 'session-secret', 'token-secret', 'a1b2c3d4e5', 'JBSWY3DPEHPK3PXP', '$argon2id$'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function testRoundTripKeepsWhatTheWorkerReads(): void
    {
        // The queue JSON-encodes the message; decode it the way a consumer receives it.
        $data = \json_decode(\json_encode($this->message()->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $message = Audit::fromArray($data);

        $this->assertSame('user-id', $message->user->getId());
        $this->assertSame('7', (string) $message->user->getSequence());
        $this->assertSame('Jane', $message->user->getAttribute('name'));
        $this->assertSame('jane@example.test', $message->user->getAttribute('email'));
        $this->assertSame('user', $message->user->getAttribute('type'));
        $this->assertSame('admin-id', $message->impersonatorUser->getId());
        $this->assertSame('project-id', $message->project->getId());
        $this->assertSame('user.update', $message->event);
        $this->assertSame('user/user-id', $message->resource);
        $this->assertSame('admin', $message->mode);
        $this->assertSame('203.0.113.10', $message->ip);
        $this->assertSame('Mozilla/5.0', $message->userAgent);
        $this->assertSame('cloud.appwrite.io', $message->hostname);
        $this->assertSame('web', $message->sdk);
        $this->assertSame('18.0.0', $message->sdkVersion);
        $this->assertSame([], $message->payload);
    }

    public function testReadsMessagesQueuedBeforeTheChange(): void
    {
        // A message published by an older producer carries the whole user document and the payload.
        $legacy = [
            'project' => ['$id' => 'project-id', '$sequence' => 42],
            'user' => $this->storedUser('user-id', '7', 'Jane', 'jane@example.test')->getArrayCopy(),
            'impersonatorUser' => [],
            'payload' => ['$id' => 'user-id', 'password' => 'response-body'],
            'resource' => 'user/user-id',
            'mode' => 'default',
            'ip' => '203.0.113.10',
            'userAgent' => 'Mozilla/5.0',
            'event' => 'user.update',
            'hostname' => 'cloud.appwrite.io',
        ];

        $message = Audit::fromArray($legacy);

        $this->assertSame('user-id', $message->user->getId());
        $this->assertSame('Jane', $message->user->getAttribute('name'));
        $this->assertSame('jane@example.test', $message->user->getAttribute('email'));
        $this->assertSame('user', $message->user->getAttribute('type'));
        $this->assertTrue($message->impersonatorUser->isEmpty());
        $this->assertSame('', $message->sdk);
        $this->assertSame('', $message->sdkVersion);

        // Re-publishing (retry, dead-letter replay) must not carry the legacy secrets forward.
        $data = $message->toArray();
        $this->assertArrayNotHasKey('payload', $data);
        foreach (self::SECRET_USER_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $data['user']);
        }
    }

    public function testReadsMessagesWithMissingFields(): void
    {
        $message = Audit::fromArray(['event' => 'user.update']);

        $this->assertSame('user.update', $message->event);
        $this->assertTrue($message->user->isEmpty());
        $this->assertTrue($message->impersonatorUser->isEmpty());
        $this->assertTrue($message->project->isEmpty());
        $this->assertSame('', $message->resource);
    }
}
