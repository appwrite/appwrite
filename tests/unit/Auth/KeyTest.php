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
        $projectId = 'test';
        $usage = false;
        $scopes = [
            'databases.read',
            'collections.read',
            'documents.read',
        ];
        $roleScopes = Config::getParam('roles', [])[User::ROLE_APPS]['scopes'];

        $key = static::generateKey($projectId, $usage, $scopes);
        $project = new Document(['$id' => $projectId,]);
        $decoded = Key::decode($project, $key);

        $this->assertEquals($projectId, $decoded->getProjectId());
        $this->assertEquals(API_KEY_DYNAMIC, $decoded->getType());
        $this->assertEquals(User::ROLE_APPS, $decoded->getRole());
        $this->assertEquals(\array_merge($scopes, $roleScopes), $decoded->getScopes());
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
