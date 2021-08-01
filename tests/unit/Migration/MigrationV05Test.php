<?php

namespace Appwrite\Tests;

use ReflectionClass;
use Appwrite\Migration\Version\V05;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Utopia\Config\Config;

class MigrationV05Test extends MigrationTest
{
    public function setUp(): void
    {
        Config::load('providers', __DIR__ . '/../../../app/config/providers.php');

        $this->pdo = new \PDO('sqlite::memory:');
        $this->migration = new V05($this->pdo);
        $reflector = new ReflectionClass('Appwrite\Migration\Version\V05');
        $this->method = $reflector->getMethod('fixDocument');
        $this->method->setAccessible(true);
    }

    public function testMigration()
    {
        $document = $this->fixDocument(new Document([
            '$uid' => 'unique',
            '$collection' => Database::SYSTEM_COLLECTION_PROJECTS,
            'usersOauthGithubAppid' => 123,
            'usersOauthGithubSecret' => 456
        ]));

        $this->assertEquals($document->getAttribute('$uid', null), null);
        $this->assertEquals($document->getAttribute('$id', null), 'unique');

        $this->assertEquals($document->getAttribute('usersOauthGithubAppid', null), null);
        $this->assertEquals($document->getAttribute('usersOauth2GithubAppid', null), 123);

        $this->assertEquals($document->getAttribute('usersOauthGithubSecret', null), null);
        $this->assertEquals($document->getAttribute('usersOauth2GithubSecret', null), 456);

        $this->assertEquals($document->getAttribute('security', true), false);

        $document = $this->fixDocument(new Document([
            '$uid' => 'unique',
            '$collection' => Database::SYSTEM_COLLECTION_TASKS
        ]));

        $this->assertEquals($document->getAttribute('$uid', null), null);
        $this->assertEquals($document->getAttribute('$id', null), 'unique');

        $this->assertEquals($document->getAttribute('security', true), false);

        $document = $this->fixDocument(new Document([
            '$uid' => 'unique',
            '$collection' => Database::SYSTEM_COLLECTION_USERS,
            'oauthGithub' => 'id',
            'oauthGithubAccessToken' => 'token',
            'confirm' => false
        ]));

        $this->assertEquals($document->getAttribute('$uid', null), null);
        $this->assertEquals($document->getAttribute('$id', null), 'unique');

        $this->assertEquals($document->getAttribute('confirm', null), null);
        $this->assertEquals($document->getAttribute('emailVerification', true), false);

        $this->assertEquals($document->getAttribute('oauthGithub', null), null);
        $this->assertEquals($document->getAttribute('oauth2Github', null), 'id');

        $this->assertEquals($document->getAttribute('oauthGithubAccessToken', null), null);
        $this->assertEquals($document->getAttribute('oauth2GithubAccessToken', null), 'token');

        $document = $this->fixDocument(new Document([
            '$uid' => 'unique',
            '$collection' => Database::SYSTEM_COLLECTION_PLATFORMS,
            'url' => 'https://appwrite.io'
        ]));

        $this->assertEquals($document->getAttribute('$uid', null), null);
        $this->assertEquals($document->getAttribute('$id', null), 'unique');

        $this->assertEquals($document->getAttribute('url', null), null);
        $this->assertEquals($document->getAttribute('hostname', null), 'appwrite.io');
    }
}
