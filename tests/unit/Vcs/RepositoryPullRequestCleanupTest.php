<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\RepositoryPullRequestCleanup;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class RepositoryPullRequestCleanupTest extends TestCase
{
    public function testRemoveOnlyUpdatesGitlabRepositories(): void
    {
        // A GitHub repo and a GitLab repo can share the same numeric
        // providerRepositoryId since each is scoped to its own server.
        $gitlabRepository = new Document([
            '$id' => 'repo-gitlab',
            'installationId' => 'install-gitlab',
            'providerRepositoryId' => '5',
            'providerPullRequestIds' => [5],
        ]);

        $githubRepository = new Document([
            '$id' => 'repo-github',
            'installationId' => 'install-github',
            'providerRepositoryId' => '5',
            'providerPullRequestIds' => [5],
        ]);

        $gitlabInstallation = new Document(['$id' => 'install-gitlab', 'provider' => 'gitlab']);
        $githubInstallation = new Document(['$id' => 'install-github', 'provider' => 'github']);

        $dbForPlatform = $this->createMock(Database::class);
        $authorization = $this->createStub(Authorization::class);

        $dbForPlatform->method('find')->willReturn([$gitlabRepository, $githubRepository]);

        $dbForPlatform->method('getDocument')
            ->willReturnCallback(fn (string $collection, string $id): Document => match ($id) {
                'install-gitlab' => $gitlabInstallation,
                'install-github' => $githubInstallation,
                default => new Document(),
            });

        $authorization->method('skip')->willReturnCallback(fn (callable $fn): mixed => $fn());

        $dbForPlatform->expects($this->once())
            ->method('updateDocument')
            ->with('repositories', 'repo-gitlab', $this->callback(function (Document $doc) {
                $this->assertSame([], array_values($doc->getAttribute('providerPullRequestIds')));
                return true;
            }));

        (new RepositoryPullRequestCleanup())->remove($dbForPlatform, $authorization, 'gitlab', '5', 5);
    }
}
