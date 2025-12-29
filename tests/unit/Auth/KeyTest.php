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

        // Decode depricated standard key
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

        // Decode account key
        // Decode invalid account key
        // Decode expired account key
        // Decode organization key
        // Decode invalid organization key
        // Decode exired organization key
    }

    private static function generateKey(
        string $projectId,
        bool $usage,
        array $scopes,
    ): string {
        $jwt = new JWT(
            key: System::getEnv('_APP_OPENSSL_KEY_V1'),
            algo: 'HS256',
            maxAge: 86400,
            leeway: 0,
        );

        $apiKey = $jwt->encode([
            'projectId' => $projectId,
            'usage' => $usage,
            'scopes' => $scopes,
        ]);

        return API_KEY_DYNAMIC . '_' . $apiKey;
    }
}
