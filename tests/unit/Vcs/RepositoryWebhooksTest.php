<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Extend\Exception;
use Appwrite\Vcs\Factory;
use Appwrite\Vcs\RepositoryWebhooks;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\VCS\Adapter\Git;

final class RepositoryWebhooksTest extends TestCase
{
    public function testSkipsWhenAdapterDoesNotRequireWebhook(): void
    {
        $adapter = $this->createMock(Git::class);
        $adapter->method('requiresRepositoryWebhook')->willReturn(false);
        $adapter->expects($this->never())->method('createWebhook');

        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('count');

        $installation = new Document(['$sequence' => 1, 'provider' => 'github']);

        $this->ensure($adapter, $installation, $db, $this->factory('secret'));
    }

    public function testCreatesWebhookWhenNoExistingConnection(): void
    {
        $adapter = $this->createMock(Git::class);
        $adapter->method('requiresRepositoryWebhook')->willReturn(true);
        $adapter->expects($this->once())
            ->method('createWebhook')
            ->with('owner', 'repo', 'https://example.com/v1/vcs/gitea/events', 'secret')
            ->willReturn(1);

        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('count')->willReturn(1);

        $installation = new Document(['$sequence' => 1, 'provider' => 'gitea']);

        $this->ensure($adapter, $installation, $db, $this->factory('secret'));
    }

    public function testSkipsWhenRepositoryAlreadyConnected(): void
    {
        $adapter = $this->createMock(Git::class);
        $adapter->method('requiresRepositoryWebhook')->willReturn(true);
        $adapter->expects($this->never())->method('createWebhook');

        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('count')->willReturn(2);

        $installation = new Document(['$sequence' => 1, 'provider' => 'gitea']);

        $this->ensure($adapter, $installation, $db, $this->factory('secret'));
    }

    public function testAdapterFailureIsWrapped(): void
    {
        $adapter = $this->createMock(Git::class);
        $adapter->method('requiresRepositoryWebhook')->willReturn(true);
        $adapter->method('createWebhook')->willThrowException(new \RuntimeException('provider unreachable'));

        $db = $this->createMock(Database::class);
        $db->method('count')->willReturn(1);

        $installation = new Document(['$sequence' => 1, 'provider' => 'gitea']);

        $this->expectException(Exception::class);
        $this->ensure($adapter, $installation, $db, $this->factory('secret'));
    }

    protected function ensure(Git $adapter, Document $installation, Database $db, Factory $vcsFactory): void
    {
        \putenv('_APP_VCS_WEBHOOK_URL=https://example.com');

        (new RepositoryWebhooks($vcsFactory))->ensure($adapter, $installation, $db, 'repo-1', 'owner', 'repo');

        \putenv('_APP_VCS_WEBHOOK_URL');
    }

    protected function factory(string $secret): Factory
    {
        $factory = $this->createMock(Factory::class);
        $factory->method('getWebhookSecret')->willReturn($secret);

        return $factory;
    }
}
