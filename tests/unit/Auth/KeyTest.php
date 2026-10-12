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
        $this->assertFalse($decoded->isRuntimeEphemeral());

        // Decode ephemeral key with extras
        $extra = [
            'disabledMetrics' => ['metric123'],
            'hostnameOverride' => true,
            'bannerDisabled' => true,
            'projectCheckDisabled' => true,
            'previewAuthDisabled' => true,
            'deploymentStatusIgnored' => true,
            'runtimeEphemeral' => true,
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
        $this->assertTrue($decoded->isRuntimeEphemeral());

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
     * @return array<string, array{0: Document, 1: Document, 2: Document, 3: string}>
     */
    public static function invalidKeys(): array
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

        return [
            'invalid ephemeral jwt' => [
                new Document(['$id' => $projectId]),
                new Document(),
                new Document(),
                API_KEY_EPHEMERAL . '_invalid_jwt_token',
            ],
            'invalid dynamic jwt' => [
                new Document(['$id' => $projectId]),
                new Document(),
                new Document(),
                'dynamic_totally_invalid',
            ],
            'mismatched ephemeral project' => [
                new Document(['$id' => $projectId]),
                new Document(),
                new Document(),
                self::generateKey('other-project', false, ['documents.read']),
            ],
            'invalid standard key' => [
                $storedStandard,
                new Document(),
                new Document(),
                'standard_efgh5678',
            ],
            'unknown prefix' => [
                new Document(['$id' => $projectId]),
                new Document(),
                new Document(),
                'unknown_prefix_secret',
            ],
            'random secret' => [
                new Document(['$id' => $projectId]),
                new Document(),
                new Document(),
                'not-a-real-key',
            ],
            'invalid account key' => [
                new Document(['$id' => $projectId]),
                new Document(),
                $storedAccount,
                'account_efgh5678',
            ],
            'invalid organization key' => [
                new Document(['$id' => $projectId]),
                $storedOrganization,
                new Document(),
                'organization_efgh5678',
            ],
        ];
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
            'projectId' => $projectId,
            'usage' => $usage,
            'scopes' => $scopes,
        ], $extra));

        return API_KEY_EPHEMERAL . '_' . $apiKey;
    }
}
