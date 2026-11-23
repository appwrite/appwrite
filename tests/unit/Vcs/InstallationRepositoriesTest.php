<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Extend\Exception;
use Appwrite\Vcs\InstallationRepositories;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\RepositoryNotFound;

final class InstallationRepositoriesTest extends TestCase
{
    public function testResolvesWithoutOwnershipCheckForRepositoryScopedWebhooks(): void
    {
        $adapter = $this->adapter([Git::WEBHOOK_SCOPE_REPOSITORY]);
        $adapter->expects($this->never())->method('getRepository');
        $adapter->expects($this->never())->method('getInstallationRepository');

        $this->assertSame(['owner', 'repo'], $this->resolve($adapter));
    }

    public function testResolvesRepositoryOwnedByInstallation(): void
    {
        $adapter = $this->adapter([Git::WEBHOOK_SCOPE_INSTALLATION]);
        $adapter->expects($this->once())->method('getRepository')->with('owner', 'repo')->willReturn(['id' => 42]);
        $adapter->method('hasAccessToAllRepositories')->willReturn(true);
        $adapter->expects($this->never())->method('getInstallationRepository');

        $this->assertSame(['owner', 'repo'], $this->resolve($adapter));
    }

    public function testChecksSelectedRepositoriesWhenInstallationIsNotGrantedAll(): void
    {
        $adapter = $this->adapter([Git::WEBHOOK_SCOPE_INSTALLATION]);
        $adapter->method('getRepository')->willReturn(['id' => '42']);
        $adapter->method('hasAccessToAllRepositories')->willReturn(false);
        $adapter->expects($this->once())->method('getInstallationRepository')->with('repo')->willReturn(['id' => '42']);

        $this->assertSame(['owner', 'repo'], $this->resolve($adapter));
    }

    public function testRejectsRepositoryWithDifferentId(): void
    {
        $adapter = $this->adapter([Git::WEBHOOK_SCOPE_INSTALLATION]);
        $adapter->method('getRepository')->willReturn(['id' => '7']);

        $this->assertRepositoryNotFound($adapter);
    }

    public function testRejectsRepositoryOutsideSelectedRepositories(): void
    {
        $adapter = $this->adapter([Git::WEBHOOK_SCOPE_INSTALLATION]);
        $adapter->method('getRepository')->willReturn(['id' => '42']);
        $adapter->method('hasAccessToAllRepositories')->willReturn(false);
        $adapter->method('getInstallationRepository')->willThrowException(new RepositoryNotFound('Repository not found.'));

        $this->assertRepositoryNotFound($adapter);
    }

    public function testMapsMissingRepositoryToProviderError(): void
    {
        $adapter = $this->createStub(Git::class);
        $adapter->method('getOwnerName')->willThrowException(new RepositoryNotFound('Repository not found.'));

        $this->assertRepositoryNotFound($adapter);
    }

    /**
     * @param array<string> $scopes
     */
    private function adapter(array $scopes): Git&MockObject
    {
        $adapter = $this->createMock(Git::class);
        $adapter->method('getOwnerName')->with('installation-1', 42)->willReturn('owner');
        $adapter->method('getRepositoryName')->with('42')->willReturn('repo');
        $adapter->method('getSupportedWebhookScopes')->willReturn($scopes);

        return $adapter;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolve(Git $adapter): array
    {
        $installation = new Document(['providerInstallationId' => 'installation-1']);

        return (new InstallationRepositories())->resolve($adapter, $installation, '42');
    }

    private function assertRepositoryNotFound(Git $adapter): void
    {
        try {
            $this->resolve($adapter);
            $this->fail('Expected the repository to be rejected.');
        } catch (Exception $error) {
            $this->assertSame(Exception::PROVIDER_REPOSITORY_NOT_FOUND, $error->getType());
        }
    }
}
