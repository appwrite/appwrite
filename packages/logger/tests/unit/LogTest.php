<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Logger\Log;
use Utopia\Logger\Log\Breadcrumb;
use Utopia\Logger\Log\User;

class LogTest extends TestCase
{
    /**
     * @throws \Throwable
     */
    public function testLog(): void
    {
        $log = new Log();

        $timestamp = \microtime(true);
        $log->setTimestamp($timestamp);
        self::assertEquals($timestamp, $log->getTimestamp());

        $log->setType(Log::TYPE_ERROR);
        self::assertEquals(Log::TYPE_ERROR, $log->getType());
        $log->setType(Log::TYPE_DEBUG);
        self::assertEquals(Log::TYPE_DEBUG, $log->getType());
        $log->setType(Log::TYPE_WARNING);
        self::assertEquals(Log::TYPE_WARNING, $log->getType());
        $log->setType(Log::TYPE_VERBOSE);
        self::assertEquals(Log::TYPE_VERBOSE, $log->getType());
        $log->setType(Log::TYPE_INFO);
        self::assertEquals(Log::TYPE_INFO, $log->getType());

        $log->setMessage("Cannot read 'user' of undefined");
        self::assertEquals("Cannot read 'user' of undefined", $log->getMessage());

        $log->setVersion('0.11.0');
        self::assertEquals('0.11.0', $log->getVersion());

        $log->setEnvironment(Log::ENVIRONMENT_PRODUCTION);
        self::assertEquals(Log::ENVIRONMENT_PRODUCTION, $log->getEnvironment());
        $log->setEnvironment(Log::ENVIRONMENT_STAGING);
        self::assertEquals(Log::ENVIRONMENT_STAGING, $log->getEnvironment());

        $log->setNamespace('getAuthUser');
        self::assertEquals('getAuthUser', $log->getNamespace());

        $log->setAction('authGuard');
        self::assertEquals('authGuard', $log->getAction());

        $log->setServer('aws-001');
        self::assertEquals('aws-001', $log->getServer());

        $log->addExtra('isLoggedIn', false);
        self::assertEquals(['isLoggedIn' => false], $log->getExtra());

        $log->addTag('authMethod', 'session');
        $log->addTag('authProvider', 'basic');
        self::assertEquals(['authMethod' => 'session', 'authProvider' => 'basic'], $log->getTags());

        $userId = 'myid123';
        $user = new User($userId);
        $log->setUser($user);
        self::assertEquals($user, $log->getUser());
        self::assertEquals($userId, $log->getUser()?->getId());

        $breadcrumb = new Breadcrumb(Log::TYPE_DEBUG, 'http', 'DELETE /api/v1/database/abcd1234/efgh5678', $timestamp);
        $log->addBreadcrumb($breadcrumb);
        self::assertEquals([$breadcrumb], $log->getBreadcrumbs());
        self::assertEquals(Log::TYPE_DEBUG, $log->getBreadcrumbs()[0]->getType());
        self::assertEquals('http', $log->getBreadcrumbs()[0]->getCategory());
        self::assertEquals('DELETE /api/v1/database/abcd1234/efgh5678', $log->getBreadcrumbs()[0]->getMessage());
        self::assertEquals($timestamp, $log->getBreadcrumbs()[0]->getTimestamp());
    }

    public function testLogMasked(): void
    {
        $log = new Log();

        $log->addTag('password', '123456');
        $log->addExtra('name', 'John Doe');

        self::assertEquals(['password' => '123456'], $log->getTags());
        self::assertEquals(['name' => 'John Doe'], $log->getExtra());

        $log->setMasked(['password', 'name']);

        self::assertEquals(['password' => '******'], $log->getTags());
        self::assertEquals(['name' => '********'], $log->getExtra());

        // test nested array
        $log->addExtra('user', ['password' => 'abc']);

        self::assertEquals(['password' => '***'], $log->getExtra()['user']);

        // test remove mask
        $log->setMasked([]);

        self::assertEquals(['password' => '123456'], $log->getTags());
        self::assertEquals(['name' => 'John Doe', 'user' => ['password' => 'abc']], $log->getExtra());
    }
}
