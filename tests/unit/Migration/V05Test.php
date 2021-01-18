<?php

namespace Appwrite\Tests;

use ReflectionClass;
use Appwrite\Migration\Version\V05;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;

class V05Test extends TestCase
{
    /**
     * @var PDO
     */
    protected \PDO $pdo;

    public function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
    }

    public function testMigration()
    {
        Config::load('providers', __DIR__ . '/../../../app/config/providers.php');

        $v05 = new V05($this->pdo);

        $reflector = new ReflectionClass('Appwrite\Migration\Version\V05');
        $method = $reflector->getMethod('fixDocument');
        $method->setAccessible(true);

        $document =  $method->invokeArgs($v05, [
            new Document([
                '$uid' => 'unique',
                '$collection' => Database::SYSTEM_COLLECTION_PROJECTS,
                'usersOauthGithubAppid' => 123,
                'usersOauthGithubSecret' => 456
            ])
        ]);

        $this->assertEquals($document->getAttribute('$uid', null), null);
        $this->assertEquals($document->getAttribute('$id', null), 'unique');

        $this->assertEquals($document->getAttribute('usersOauthGithubAppid', null), null);
        $this->assertEquals($document->getAttribute('usersOauth2GithubAppid', null), 123);

        $this->assertEquals($document->getAttribute('usersOauthGithubSecret', null), null);
        $this->assertEquals($document->getAttribute('usersOauth2GithubSecret', null), 456);

        $this->assertEquals($document->getAttribute('security', true), false);

        $document =  $method->invokeArgs($v05, [
            new Document([
                '$uid' => 'unique',
                '$collection' => Database::SYSTEM_COLLECTION_TASKS
            ])
        ]);

        $this->assertEquals($document->getAttribute('$uid', null), null);
        $this->assertEquals($document->getAttribute('$id', null), 'unique');

        $this->assertEquals($document->getAttribute('security', true), false);

        $document =  $method->invokeArgs($v05, [
            new Document([
                '$uid' => 'unique',
                '$collection' => Database::SYSTEM_COLLECTION_USERS,
                'oauthGithub' => 'id',
                'oauthGithubAccessToken' => 'token',
                'confirm' => false
            ])
        ]);

        $this->assertEquals($document->getAttribute('$uid', null), null);
        $this->assertEquals($document->getAttribute('$id', null), 'unique');

        $this->assertEquals($document->getAttribute('confirm', null), null);
        $this->assertEquals($document->getAttribute('emailVerification', true), false);

        $this->assertEquals($document->getAttribute('oauthGithub', null), null);
        $this->assertEquals($document->getAttribute('oauth2Github', null), 'id');

        $this->assertEquals($document->getAttribute('oauthGithubAccessToken', null), null);
        $this->assertEquals($document->getAttribute('oauth2GithubAccessToken', null), 'token');

        $document =  $method->invokeArgs($v05, [
            new Document([
                '$uid' => 'unique',
                '$collection' => Database::SYSTEM_COLLECTION_PLATFORMS,
                'url' => 'https://appwrite.io'
            ])
        ]);

        $this->assertEquals($document->getAttribute('$uid', null), null);
        $this->assertEquals($document->getAttribute('$id', null), 'unique');

        $this->assertEquals($document->getAttribute('url', null), null);
        $this->assertEquals($document->getAttribute('hostname', null), 'appwrite.io');
    }
}
