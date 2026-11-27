<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Reviews\Provider;
use Appwrite\Vcs\RepositoryPath;
use PHPUnit\Framework\TestCase;
use Utopia\VCS\Adapter\Git;

final class RepositoryPathTest extends TestCase
{
    public function testReadsOwnerAndNameFromTheProviderByRepositoryId(): void
    {
        $vcs = $this->createMock(Git::class);
        $vcs->expects($this->once())->method('getRepositoryName')->with('42')->willReturn('app');
        $vcs->expects($this->once())->method('getOwnerName')->with('installation-1', 42)->willReturn('acme/group');

        $this->assertSame(['acme/group', 'app'], RepositoryPath::read($vcs, Provider::GitLab, 'installation-1', '42'));
    }

    public function testNonNumericRepositoryIdAsksForTheOwnerWithoutOne(): void
    {
        $vcs = $this->createMock(Git::class);
        $vcs->method('getRepositoryName')->willReturn('app');
        $vcs->expects($this->once())->method('getOwnerName')->with('installation-1', null)->willReturn('acme');

        $this->assertSame(['acme', 'app'], RepositoryPath::read($vcs, Provider::Gitea, 'installation-1', 'repo-abc'));
    }

    public function testBitbucketOwnerIsTheWorkspaceOfTheRepositoryId(): void
    {
        $vcs = $this->createMock(Git::class);
        $vcs->expects($this->once())->method('getRepositoryName')->with('acme%2Fapp')->willReturn('app');
        // The account's own workspace may not be the repository's.
        $vcs->expects($this->never())->method('getOwnerName');

        $this->assertSame(['acme', 'app'], RepositoryPath::read($vcs, Provider::Bitbucket, 'installation-1', 'acme%2Fapp'));
    }

    public function testRepositoryTheProviderNamesNoOwnerForIsRefused(): void
    {
        $vcs = $this->createStub(Git::class);
        $vcs->method('getRepositoryName')->willReturn('app');
        $vcs->method('getOwnerName')->willReturn('');

        $this->expectException(\Exception::class);
        RepositoryPath::read($vcs, Provider::Gitea, 'installation-1', '42');
    }

    public function testRepositoryTheProviderNamesNoNameForIsRefused(): void
    {
        $vcs = $this->createStub(Git::class);
        $vcs->method('getRepositoryName')->willReturn('');
        $vcs->method('getOwnerName')->willReturn('acme');

        $this->expectException(\Exception::class);
        RepositoryPath::read($vcs, Provider::GitLab, 'installation-1', '42');
    }
}
