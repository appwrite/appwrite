<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Ahc\Jwt\JWT;
use Appwrite\Auth\Key;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\System\System;

final class KeyTest extends TestCase
{
    public function testDecode(): void
    {
        // Decode ephemeral key
        $projectId = 'test';
        $usage = false;
        $scopes = [
            'databases.read',
            'collections.read',
            'documents.read',
        ];
        $roleScopes = Config::getParam('roles', [])[User::ROLE_KEYS]['scopes'];

        $key = self::generateKey($projectId, $usage, $scopes);
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId]),
            team: new Document(),
            user: new Document(),
            key: $key,
        );

        $this->assertSame($projectId, $decoded->getProjectId());
        $this->assertSame('', $decoded->getTeamId());
        $this->assertSame('', $decoded->getUserId());
        $this->assertSame(API_KEY_EPHEMERAL, $decoded->getType());
        $this->assertSame(User::ROLE_KEYS, $decoded->getRole());
        $this->assertEquals(\array_merge($scopes, $roleScopes), $decoded->getScopes());
        $this->assertSame('Ephemeral Key', $decoded->getName());

        // Decode ephemeral key with extras
        $extra = [
            'disabledMetrics' => ['metric123'],
            'hostnameOverride' => true,
            'bannerDisabled' => true,
            'projectCheckDisabled' => true,
            'previewAuthDisabled' => true,
            'deploymentStatusIgnored' => true,
        ];
        $key = self::generateKey($projectId, $usage, $scopes, extra: $extra);
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId]),
            team: new Document(),
            user: new Document(),
            key: $key,
        );
        $this->assertSame($projectId, $decoded->getProjectId());
        $this->assertSame('', $decoded->getTeamId());
        $this->assertSame('', $decoded->getUserId());
        $this->assertSame(API_KEY_EPHEMERAL, $decoded->getType());
        $this->assertSame(User::ROLE_KEYS, $decoded->getRole());
        $this->assertEquals(\array_merge($scopes, $roleScopes), $decoded->getScopes());
        $this->assertSame('Ephemeral Key', $decoded->getName());
        $this->assertSame(['metric123'], $decoded->getDisabledMetrics());
        $this->assertEquals(true, $decoded->getHostnameOverride());
        $this->assertEquals(true, $decoded->isBannerDisabled());
        $this->assertEquals(true, $decoded->isProjectCheckDisabled());
        $this->assertEquals(true, $decoded->isPreviewAuthDisabled());
        $this->assertEquals(true, $decoded->isDeploymentStatusIgnored());

        // Decode standard key
        $scopes = ['custom.write'];
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId, 'keys' => [
                new Document([
                    'secret' => 'standard_abcd1234',
                    'expire' => null,
                    'name' => 'Standard key',
                    'scopes' => $scopes
                ])
            ]]),
            team: new Document(),
            user: new Document(),
            key: 'standard_abcd1234',
        );
        $this->assertSame($projectId, $decoded->getProjectId());
        $this->assertSame('', $decoded->getTeamId());
        $this->assertSame('', $decoded->getUserId());
        $this->assertSame(API_KEY_STANDARD, $decoded->getType());
        $this->assertSame(User::ROLE_KEYS, $decoded->getRole());
        $this->assertEquals(\array_merge($scopes, $roleScopes), $decoded->getScopes());
        $this->assertSame('Standard key', $decoded->getName());

        // Decode deprecated standard key
        $scopes = ['custom.write'];
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId, 'keys' => [
                new Document([
                    'secret' => 'abcd1234',
                    'expire' => null,
                    'name' => 'Standard key',
                    'scopes' => ['custom.write']
                ])
            ]]),
            team: new Document(),
            user: new Document(),
            key: 'abcd1234',
        );
        $this->assertSame($projectId, $decoded->getProjectId());
        $this->assertSame('', $decoded->getTeamId());
        $this->assertSame('', $decoded->getUserId());
        $this->assertSame(API_KEY_STANDARD, $decoded->getType());
        $this->assertSame(User::ROLE_KEYS, $decoded->getRole());
        $this->assertEquals(\array_merge($scopes, $roleScopes), $decoded->getScopes());
        $this->assertSame('Standard key', $decoded->getName());

        // Decode expired standard key
        $scopes = ['custom.write'];
        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i:s\Z');
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId, 'keys' => [
                new Document([
                    'secret' => 'standard_abcd1234',
                    'expire' => $yesterday,
                    'name' => 'Standard key',
                    'scopes' => $scopes
                ])
            ]]),
            team: new Document(),
            user: new Document(),
            key: 'standard_abcd1234',
        );
        $this->assertEquals(true, $decoded->isExpired());
        $this->assertSame($projectId, $decoded->getProjectId());
        $this->assertSame('', $decoded->getTeamId());
        $this->assertSame('', $decoded->getUserId());
        $this->assertSame(API_KEY_STANDARD, $decoded->getType());
        $this->assertSame(User::ROLE_KEYS, $decoded->getRole());
        $this->assertEquals(\array_merge($scopes, $roleScopes), $decoded->getScopes());
        $this->assertSame('Standard key', $decoded->getName());

        // Decode account key
        $userId = 'user123';
        $scopes = ['teams.write'];
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId]),
            team: new Document(),
            user: new Document(['$id' => $userId, 'keys' => [
                new Document([
                    'secret' => 'account_abcd1234',
                    'expire' => null,
                    'name' => 'Account key',
                    'scopes' => $scopes
                ])
            ]]),
            key: 'account_abcd1234',
        );
        $this->assertSame('', $decoded->getProjectId());
        $this->assertSame('', $decoded->getTeamId());
        $this->assertSame($userId, $decoded->getUserId());
        $this->assertSame(API_KEY_ACCOUNT, $decoded->getType());
        $this->assertSame(User::ROLE_USERS, $decoded->getRole());
        $this->assertSame($scopes, $decoded->getScopes());
        $this->assertSame('Account key', $decoded->getName());

        // Decode expired account key
        $scopes = ['teams.write'];
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId]),
            team: new Document(),
            user: new Document(['$id' => $userId, 'keys' => [
                new Document([
                    'secret' => 'account_abcd1234',
                    'expire' => $yesterday,
                    'name' => 'Account key',
                    'scopes' => $scopes
                ])
            ]]),
            key: 'account_abcd1234',
        );
        $this->assertEquals(true, $decoded->isExpired());
        $this->assertSame('', $decoded->getProjectId());
        $this->assertSame('', $decoded->getTeamId());
        $this->assertSame($userId, $decoded->getUserId());
        $this->assertSame(API_KEY_ACCOUNT, $decoded->getType());
        $this->assertSame(User::ROLE_USERS, $decoded->getRole());
        $this->assertSame($scopes, $decoded->getScopes());
        $this->assertSame('Account key', $decoded->getName());

        // Decode organization key
        $teamId = 'team123';
        $scopes = ['projects.write'];
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId]),
            team: new Document(['$id' => $teamId, 'keys' => [
                new Document([
                    'secret' => 'organization_abcd1234',
                    'expire' => null,
                    'name' => 'Organization key',
                    'scopes' => $scopes
                ])
            ]]),
            user: new Document(),
            key: 'organization_abcd1234',
        );
        $this->assertSame('', $decoded->getProjectId());
        $this->assertSame($teamId, $decoded->getTeamId());
        $this->assertSame('', $decoded->getUserId());
        $this->assertSame(API_KEY_ORGANIZATION, $decoded->getType());
        $this->assertSame(User::ROLE_KEYS, $decoded->getRole());
        $this->assertSame($scopes, $decoded->getScopes());
        $this->assertSame('Organization key', $decoded->getName());

        // Decode expired organization key
        $scopes = ['projects.write'];
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId]),
            team: new Document(['$id' => $teamId, 'keys' => [
                new Document([
                    'secret' => 'organization_abcd1234',
                    'expire' => $yesterday,
                    'name' => 'Organization key',
                    'scopes' => $scopes
                ])
            ]]),
            user: new Document(),
            key: 'organization_abcd1234',
        );
        $this->assertEquals(true, $decoded->isExpired());
        $this->assertSame('', $decoded->getProjectId());
        $this->assertSame($teamId, $decoded->getTeamId());
        $this->assertSame('', $decoded->getUserId());
        $this->assertSame(API_KEY_ORGANIZATION, $decoded->getType());
        $this->assertSame(User::ROLE_KEYS, $decoded->getRole());
        $this->assertSame($scopes, $decoded->getScopes());
        $this->assertSame('Organization key', $decoded->getName());
    }

    #[DataProvider('invalidKeys')]
    public function testDecodeInvalid(Document $project, Document $team, Document $user, string $key): void
    {
        try {
            Key::decode(
                project: $project,
                team: $team,
                user: $user,
                key: $key,
            );
            $this->fail('Invalid key must be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::USER_UNAUTHORIZED, $error->getType());
        }
    }

    /**
     * @return \Iterator<string, array{Document, Document, Document, string}>
     */
    public static function invalidKeys(): \Iterator
    {
        $projectId = 'test';
        $userId = 'user123';
        $teamId = 'team123';
        $storedStandard = new Document(['$id' => $projectId, 'keys' => [
            new Document([
                'secret' => 'standard_abcd1234',
                'expire' => null,
                'name' => 'Standard key',
                'scopes' => ['custom.write'],
            ]),
        ]]);
        $storedAccount = new Document(['$id' => $userId, 'keys' => [
            new Document([
                'secret' => 'account_abcd1234',
                'expire' => null,
                'name' => 'Account key',
                'scopes' => ['teams.write'],
            ]),
        ]]);
        $storedOrganization = new Document(['$id' => $teamId, 'keys' => [
            new Document([
                'secret' => 'organization_abcd1234',
                'expire' => null,
                'name' => 'Organization key',
                'scopes' => ['projects.write'],
            ]),
        ]]);

        yield 'invalid ephemeral jwt' => [
            new Document(['$id' => $projectId]),
            new Document(),
            new Document(),
            API_KEY_EPHEMERAL . '_invalid_jwt_token',
        ];
        yield 'invalid dynamic jwt' => [
            new Document(['$id' => $projectId]),
            new Document(),
            new Document(),
            'dynamic_totally_invalid',
        ];
        yield 'mismatched ephemeral project' => [
            new Document(['$id' => $projectId]),
            new Document(),
            new Document(),
            self::generateKey('other-project', false, ['documents.read']),
        ];
        yield 'ephemeral jwt without purpose' => [
            new Document(['$id' => $projectId]),
            new Document(),
            new Document(),
            self::generateKey($projectId, false, ['documents.read'], extra: [Key::PURPOSE_CLAIM => null]),
        ];
        yield 'ephemeral jwt with another purpose' => [
            new Document(['$id' => $projectId]),
            new Document(),
            new Document(),
            self::generateKey($projectId, false, ['documents.read'], extra: [Key::PURPOSE_CLAIM => 'session']),
        ];
        yield 'account jwt as ephemeral key' => [
            new Document(['$id' => $projectId]),
            new Document(),
            new Document(),
            API_KEY_EPHEMERAL . '_' . (new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 0))->encode([
                'userId' => 'user123',
                'sessionId' => 'session123',
                'projectId' => $projectId,
            ]),
        ];
        yield 'dynamic jwt without purpose' => [
            new Document(['$id' => $projectId]),
            new Document(),
            new Document(),
            'dynamic_' . (new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 0))->encode([
                'projectId' => $projectId,
                'scopes' => ['documents.read'],
            ]),
        ];
        yield 'mismatched ephemeral project sequence' => [
            new Document(['$id' => $projectId, '$sequence' => '2']),
            new Document(),
            new Document(),
            self::generateKey($projectId, false, ['documents.read'], extra: ['projectSequence' => '1']),
        ];
        yield 'invalid standard key' => [
            $storedStandard,
            new Document(),
            new Document(),
            'standard_efgh5678',
        ];
        yield 'unknown prefix' => [
            new Document(['$id' => $projectId]),
            new Document(),
            new Document(),
            'unknown_prefix_secret',
        ];
        yield 'random secret' => [
            new Document(['$id' => $projectId]),
            new Document(),
            new Document(),
            'not-a-real-key',
        ];
        yield 'empty key against an empty stored secret' => [
            new Document(['$id' => $projectId, 'keys' => [
                new Document([
                    'secret' => '',
                    'expire' => null,
                    'name' => 'Empty key',
                    'scopes' => ['custom.write'],
                ]),
            ]]),
            new Document(),
            new Document(),
            '',
        ];
        yield 'invalid account key' => [
            new Document(['$id' => $projectId]),
            new Document(),
            $storedAccount,
            'account_efgh5678',
        ];
        yield 'invalid organization key' => [
            new Document(['$id' => $projectId]),
            $storedOrganization,
            new Document(),
            'organization_efgh5678',
        ];
    }

    public function testDecodeEphemeralBoundToProjectSequence(): void
    {
        $project = new Document(['$id' => 'test', '$sequence' => '42']);

        $decoded = Key::decode(
            project: $project,
            team: new Document(),
            user: new Document(),
            key: self::generateKey('test', false, ['documents.read'], extra: ['projectSequence' => '42']),
        );
        $this->assertSame('test', $decoded->getProjectId());

        $decoded = Key::decode(
            project: $project,
            team: new Document(),
            user: new Document(),
            key: self::generateKey('test', false, ['documents.read'], extra: ['projectSequence' => 42]),
        );
        $this->assertSame('test', $decoded->getProjectId());

        $decoded = Key::decode(
            project: $project,
            team: new Document(),
            user: new Document(),
            key: self::generateKey('other', false, ['documents.read'], extra: ['projectSequence' => '1', 'projectCheckDisabled' => true]),
        );
        $this->assertTrue($decoded->isProjectCheckDisabled());
    }

    public function testDecodeExpiredEphemeral(): void
    {
        $expiredKey = self::generateKey('test', false, ['documents.read'], maxAge: 1, timestamp: time() - 60);

        try {
            Key::decode(
                project: new Document(['$id' => 'test']),
                team: new Document(),
                user: new Document(),
                key: $expiredKey,
            );
            $this->fail('Expired ephemeral key must be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::USER_UNAUTHORIZED, $error->getType());
        }
    }

    public function testDecodeCarriesTheExpiry(): void
    {
        $decoded = Key::decode(
            project: new Document(['$id' => 'test']),
            team: new Document(),
            user: new Document(),
            key: self::generateKey('test', false, ['documents.read'], maxAge: 600),
        );
        $this->assertNotNull($decoded->getExpire());
        $this->assertEqualsWithDelta(\time() + 600, (new \DateTimeImmutable($decoded->getExpire()))->getTimestamp(), 5);

        $expire = '2030-01-01T00:00:00.000+00:00';
        $decoded = Key::decode(
            project: new Document(['$id' => 'test', 'keys' => [new Document(['$id' => 'k', 'secret' => 'standard_abcd', 'scopes' => [], 'expire' => $expire])]]),
            team: new Document(),
            user: new Document(),
            key: 'standard_abcd',
        );
        $this->assertSame($expire, $decoded->getExpire());

        $decoded = Key::decode(
            project: new Document(['$id' => 'test', 'keys' => [new Document(['$id' => 'k', 'secret' => 'standard_abcd', 'scopes' => [], 'expire' => null])]]),
            team: new Document(),
            user: new Document(),
            key: 'standard_abcd',
        );
        $this->assertNull($decoded->getExpire());
    }

    /**
     * @return \Iterator<string, array{string, array<string>, ?string, string, array<string>, ?string, bool}>
     */
    public static function creations(): \Iterator
    {
        $soon = '2030-01-01T00:00:00.000+00:00';
        $later = '2031-01-01T00:00:00.000+00:00';
        $earlier = '2029-01-01T00:00:00.000+00:00';

        // A key delegates downwards: never a scope it does not hold
        yield 'standard key mints an ephemeral key with its scopes' => [API_KEY_STANDARD, ['keys.write', 'users.read'], null, API_KEY_EPHEMERAL, ['users.read'], $earlier, true];
        yield 'standard key mints an ephemeral key with a scope it lacks' => [API_KEY_STANDARD, ['keys.write', 'users.read'], null, API_KEY_EPHEMERAL, ['users.write'], $earlier, false];
        yield 'standard key widens a key to scopes it lacks' => [API_KEY_STANDARD, ['keys.write', 'users.read'], null, API_KEY_STANDARD, ['users.read', 'databases.write'], null, false];
        yield 'standard key without expiry sets no expiry' => [API_KEY_STANDARD, ['keys.write', 'users.read'], null, API_KEY_STANDARD, ['users.read'], null, true];

        // ... and never past its own expiry, so it cannot renew itself
        yield 'ephemeral key mints one that expires after it' => [API_KEY_EPHEMERAL, ['keys.write'], $soon, API_KEY_EPHEMERAL, [], $later, false];
        yield 'ephemeral key mints one that expires with it' => [API_KEY_EPHEMERAL, ['keys.write'], $soon, API_KEY_EPHEMERAL, [], $soon, true];
        yield 'expiring standard key sets no expiry' => [API_KEY_STANDARD, ['keys.write'], $soon, API_KEY_STANDARD, [], null, false];
        yield 'dynamic key mints one that expires after it' => ['dynamic', ['keys.write'], $soon, API_KEY_EPHEMERAL, [], $later, false];
        yield 'expiring account key creates one that never expires' => [API_KEY_ACCOUNT, ['account'], $soon, API_KEY_ACCOUNT, ['account'], null, false];
        yield 'account key creates one with a scope it lacks' => [API_KEY_ACCOUNT, ['account'], null, API_KEY_ACCOUNT, ['account', 'teams.write'], null, false];
        yield 'organization key widens an organization key' => [API_KEY_ORGANIZATION, ['organization.keys.write'], null, API_KEY_ORGANIZATION, ['organization.keys.write', 'organization.write'], null, false];

        // Organization keys provision project keys, within their own lifetime
        yield 'organization creates project key with project scopes' => [API_KEY_ORGANIZATION, ['organization.projects.keys.write'], null, API_KEY_STANDARD, ['databases.write'], null, true];
        yield 'expiring organization key creates a key that never expires' => [API_KEY_ORGANIZATION, ['organization.projects.keys.write'], $soon, API_KEY_STANDARD, ['databases.write'], null, false];
        yield 'expiring organization key creates a key that expires later' => [API_KEY_ORGANIZATION, ['organization.projects.keys.write'], $soon, API_KEY_STANDARD, [], $later, false];
        yield 'expiring organization key creates a key that expires with it' => [API_KEY_ORGANIZATION, ['organization.projects.keys.write'], $soon, API_KEY_STANDARD, [], $soon, true];
        yield 'expiring organization key creates a key that expires sooner' => [API_KEY_ORGANIZATION, ['organization.projects.keys.write'], $soon, API_KEY_EPHEMERAL, ['users.read'], $earlier, true];
    }

    /**
     * @param array<string> $callerScopes
     * @param array<string> $scopes
     */
    #[DataProvider('creations')]
    public function testMayCreateNothingBeyondItsGrant(string $callerType, array $callerScopes, ?string $callerExpire, string $type, array $scopes, ?string $expire, bool $allowed): void
    {
        $caller = new Key('test', '', '', $callerType, User::ROLE_KEYS, $callerScopes, 'Caller', expire: $callerExpire);

        $this->assertSame($allowed, $caller->mayCreate($type, $scopes, $expire));
    }

    public function testMayCreateComparesExpiryAcrossTimezones(): void
    {
        $caller = new Key('test', '', '', API_KEY_ORGANIZATION, User::ROLE_KEYS, [], 'Caller', expire: '2030-01-01T12:00:00.000+00:00');

        // 13:00 in Prague is 12:00 UTC: the same instant, not a later one
        $this->assertTrue($caller->mayCreate(API_KEY_STANDARD, [], '2030-01-01T13:00:00.000+01:00'));
        $this->assertFalse($caller->mayCreate(API_KEY_STANDARD, [], '2030-01-01T12:00:01.000+00:00'));
    }

    private static function generateKey(
        string $projectId,
        bool $usage,
        array $scopes,
        int $maxAge = 86400,
        ?int $timestamp = null,
        array $extra = []
    ): string {
        $jwt = new JWT(
            key: System::getEnv('_APP_OPENSSL_KEY_V1'),
            algo: 'HS256',
            maxAge: $maxAge,
            leeway: 0,
        );
        $jwt->setTestTimestamp($timestamp);

        $apiKey = $jwt->encode(\array_merge([
            Key::PURPOSE_CLAIM => Key::PURPOSE,
            'projectId' => $projectId,
            'usage' => $usage,
            'scopes' => $scopes,
        ], $extra));

        return API_KEY_EPHEMERAL . '_' . $apiKey;
    }
}
