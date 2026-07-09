<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Extend\Exception;
use Appwrite\Vcs\RepositoryWebhooks;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\VCS\Adapter\Git;

final class RepositoryWebhooksTest extends TestCase
{
    public function testCreatesWebhookWhenNoExistingConnection(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('count')->willReturn(1);

        $adapter = $this->createMock(Git::class);
        $adapter->expects($this->once())
            ->method('createWebhook')
            ->with('owner', 'repo', 'https://example.com/v1/vcs/gitea/events', 'secret')
            ->willReturn(1);

        $installation = new Document(['$sequence' => 1]);

        (new RepositoryWebhooks())->ensure($adapter, $installation, $db, 'repo-1', 'owner', 'repo', 'https://example.com/v1/vcs/gitea/events', 'secret');
    }

    public function testSkipsWhenRepositoryAlreadyConnected(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('count')->willReturn(2);

        $adapter = $this->createMock(Git::class);
        $adapter->expects($this->never())->method('createWebhook');

        $installation = new Document(['$sequence' => 1]);

        (new RepositoryWebhooks())->ensure($adapter, $installation, $db, 'repo-1', 'owner', 'repo', 'https://example.com/v1/vcs/gitea/events', 'secret');
    }

    public function testAdapterFailureIsWrapped(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('count')->willReturn(1);

        $adapter = $this->createMock(Git::class);
        $adapter->method('createWebhook')->willThrowException(new \RuntimeException('provider unreachable'));

        $installation = new Document(['$sequence' => 1]);

        $this->expectException(Exception::class);
        (new RepositoryWebhooks())->ensure($adapter, $installation, $db, 'repo-1', 'owner', 'repo', 'https://example.com/v1/vcs/gitea/events', 'secret');
    }
}
