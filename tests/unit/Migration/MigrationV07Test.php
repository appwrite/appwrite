<?php

namespace Appwrite\Tests;

use ReflectionClass;
use Appwrite\Migration\Version\V07;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Auth\Auth;
use Utopia\Config\Config;

class MigrationV07Test extends MigrationTest
{
    public function setUp(): void
    {
        Config::load('providers', __DIR__ . '/../../../app/config/providers.php');

        $this->pdo = new \PDO('sqlite::memory:');
        $this->migration = new V07($this->pdo);
        $reflector = new ReflectionClass('Appwrite\Migration\Version\V07');
        $this->method = $reflector->getMethod('fixDocument');
        $this->method->setAccessible(true);
    }

    public function testMigration()
    {
        $document = $this->fixDocument(new Document([
            '$id' => 'unique',
            '$collection' => Database::SYSTEM_COLLECTION_USERS,
            'oauth2Github' => 123,
            'oauth2GithubAccessToken' => 456,
            'tokens' => [
                new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                    'userId' => 'unique',
                    'type' => Auth::TOKEN_TYPE_LOGIN,
                    'secret' => 'login',
                ]),
                new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                    'userId' => 'unique',
                    'type' => Auth::TOKEN_TYPE_INVITE,
                    'secret' => 'invite',
                ]),
                new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                    'userId' => 'unique',
                    'type' => Auth::TOKEN_TYPE_RECOVERY,
                    'secret' => 'recovery',
                ]),
                new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                    'userId' => 'unique',
                    'type' => Auth::TOKEN_TYPE_VERIFICATION,
                    'secret' => 'verification',
                ]),
            ]
        ]));

        $this->assertEquals($document->getAttribute('oauth2Github', null), null);
        $this->assertEquals($document->getAttribute('oauth2GithubAccessToken', null), null);

        $this->assertCount(3, $document->getAttribute('tokens', []));
        $this->assertEquals(Auth::TOKEN_TYPE_INVITE, $document->getAttribute('tokens', [])[0]['type']);
        $this->assertEquals(Auth::TOKEN_TYPE_RECOVERY, $document->getAttribute('tokens', [])[1]['type']);
        $this->assertEquals(Auth::TOKEN_TYPE_VERIFICATION, $document->getAttribute('tokens', [])[2]['type']);

    }
}
