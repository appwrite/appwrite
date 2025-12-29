<?php

namespace Tests\Unit\Auth;

use Ahc\Jwt\JWT;
use Appwrite\Auth\Key;
use Appwrite\Utopia\Database\Documents\User;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\System\System;

class KeyTest extends TestCase
{
    public function testDecode(): void
    {
        // Decode dynamic key
        $projectId = 'test';
        $usage = false;
        $scopes = [
            'databases.read',
            'collections.read',
            'documents.read',
        ];
        $roleScopes = Config::getParam('roles', [])[User::ROLE_APPS]['scopes'];
        $guestRoleScopes = Config::getParam('roles', [])[User::ROLE_GUESTS]['scopes'];

        $key = static::generateKey($projectId, $usage, $scopes);
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId]),
            team: new Document(),
            user: new Document(),
            key: $key,
        );

        $this->assertEquals($projectId, $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_DYNAMIC, $decoded->getType());
        $this->assertEquals(User::ROLE_APPS, $decoded->getRole());
        $this->assertEquals(\array_merge($scopes, $roleScopes), $decoded->getScopes());
        $this->assertEquals('Dynamic Key', $decoded->getName());

        // Decode dynamic key with extras
        $extra = [
            'disabledMetrics' => ['metric123'],
            'hostnameOverride' => true,
            'bannerDisabled' => true,
            'projectCheckDisabled' => true,
            'previewAuthDisabled' => true,
            'deploymentStatusIgnored' => true,
        ];
        $key = static::generateKey($projectId, $usage, $scopes, extra: $extra);
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId]),
            team: new Document(),
            user: new Document(),
            key: $key,
        );
        $this->assertEquals($projectId, $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_DYNAMIC, $decoded->getType());
        $this->assertEquals(User::ROLE_APPS, $decoded->getRole());
        $this->assertEquals(\array_merge($scopes, $roleScopes), $decoded->getScopes());
        $this->assertEquals('Dynamic Key', $decoded->getName());
        $this->assertEquals(['metric123'], $decoded->getDisabledMetrics());
        $this->assertEquals(true, $decoded->getHostnameOverride());
        $this->assertEquals(true, $decoded->isBannerDisabled());
        $this->assertEquals(true, $decoded->isProjectCheckDisabled());
        $this->assertEquals(true, $decoded->isPreviewAuthDisabled());
        $this->assertEquals(true, $decoded->isDeploymentStatusIgnored());

        // Decode invalid dynamic key
        $invalidKey = API_KEY_DYNAMIC . '_invalid_jwt_token';
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId]),
            team: new Document(),
            user: new Document(),
            key: $invalidKey,
        );
        $this->assertEquals($projectId, $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_DYNAMIC, $decoded->getType());
        $this->assertEquals(User::ROLE_GUESTS, $decoded->getRole());
        $this->assertEquals($guestRoleScopes, $decoded->getScopes());
        $this->assertEquals('UNKNOWN', $decoded->getName());

        // Decode expired dynamic key
        $expiredKey = static::generateKey($projectId, $usage, $scopes, maxAge: 1, timestamp: time() - 60);
        \sleep(2);
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId]),
            team: new Document(),
            user: new Document(),
            key: $expiredKey,
        );
        $this->assertEquals($projectId, $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_DYNAMIC, $decoded->getType());
        $this->assertEquals(User::ROLE_GUESTS, $decoded->getRole());
        $this->assertEquals($guestRoleScopes, $decoded->getScopes());
        $this->assertEquals('UNKNOWN', $decoded->getName());

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
        $this->assertEquals($projectId, $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_STANDARD, $decoded->getType());
        $this->assertEquals(User::ROLE_APPS, $decoded->getRole());
        $this->assertEquals(\array_merge($scopes, $roleScopes), $decoded->getScopes());
        $this->assertEquals('Standard key', $decoded->getName());

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
        $this->assertEquals($projectId, $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_STANDARD, $decoded->getType());
        $this->assertEquals(User::ROLE_APPS, $decoded->getRole());
        $this->assertEquals(\array_merge($scopes, $roleScopes), $decoded->getScopes());
        $this->assertEquals('Standard key', $decoded->getName());

        // Decode invalid standard key
        $scopes = ['custom.write'];
        $decoded = Key::decode(
            project: new Document(['$id' => $projectId, 'keys' => [
                new Document([
                    'secret' => 'standard_abcd1234',
                    'expire' => null,
                    'name' => 'Standard key',
                    'scopes' => ['custom.write']
                ])
            ]]),
            team: new Document(),
            user: new Document(),
            key: 'standard_efgh5678',
        );
        $this->assertEquals($projectId, $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_STANDARD, $decoded->getType());
        $this->assertEquals(User::ROLE_GUESTS, $decoded->getRole());
        $this->assertEquals($guestRoleScopes, $decoded->getScopes());
        $this->assertEquals('UNKNOWN', $decoded->getName());

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
        $this->assertEquals($projectId, $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_STANDARD, $decoded->getType());
        $this->assertEquals(User::ROLE_APPS, $decoded->getRole());
        $this->assertEquals(\array_merge($scopes, $roleScopes), $decoded->getScopes());
        $this->assertEquals('Standard key', $decoded->getName());

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
        $this->assertEquals('', $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals($userId, $decoded->getUserId());
        $this->assertEquals(API_KEY_ACCOUNT, $decoded->getType());
        $this->assertEquals(User::ROLE_USERS, $decoded->getRole());
        $this->assertEquals($scopes, $decoded->getScopes());
        $this->assertEquals('Account key', $decoded->getName());

        // Decode invalid account key
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
            key: 'account_efgh5678',
        );
        $this->assertEquals($projectId, $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_ACCOUNT, $decoded->getType());
        $this->assertEquals(User::ROLE_GUESTS, $decoded->getRole());
        $this->assertEquals($guestRoleScopes, $decoded->getScopes());
        $this->assertEquals('UNKNOWN', $decoded->getName());

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
        $this->assertEquals('', $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals($userId, $decoded->getUserId());
        $this->assertEquals(API_KEY_ACCOUNT, $decoded->getType());
        $this->assertEquals(User::ROLE_USERS, $decoded->getRole());
        $this->assertEquals($scopes, $decoded->getScopes());
        $this->assertEquals('Account key', $decoded->getName());

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
        $this->assertEquals('', $decoded->getProjectId());
        $this->assertEquals($teamId, $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_ORGANIZATION, $decoded->getType());
        $this->assertEquals(User::ROLE_APPS, $decoded->getRole());
        $this->assertEquals($scopes, $decoded->getScopes());
        $this->assertEquals('Organization key', $decoded->getName());

        // Decode invalid organization key
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
            key: 'organization_efgh5678',
        );
        $this->assertEquals($projectId, $decoded->getProjectId());
        $this->assertEquals('', $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_ORGANIZATION, $decoded->getType());
        $this->assertEquals(User::ROLE_GUESTS, $decoded->getRole());
        $this->assertEquals($guestRoleScopes, $decoded->getScopes());
        $this->assertEquals('UNKNOWN', $decoded->getName());

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
        $this->assertEquals('', $decoded->getProjectId());
        $this->assertEquals($teamId, $decoded->getTeamId());
        $this->assertEquals('', $decoded->getUserId());
        $this->assertEquals(API_KEY_ORGANIZATION, $decoded->getType());
        $this->assertEquals(User::ROLE_APPS, $decoded->getRole());
        $this->assertEquals($scopes, $decoded->getScopes());
        $this->assertEquals('Organization key', $decoded->getName());
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

        return API_KEY_DYNAMIC . '_' . $apiKey;
    }
}
