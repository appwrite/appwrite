<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Platform\Modules\VCS\Http\Gitlab\Events\Create;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class GitlabClosedPullRequestEventTest extends TestCase
{
    private Create $handler;

    public function setUp(): void
    {
        $this->handler = new Create();
    }

    public function testClosedEventOnlyUpdatesGitlabRepositories(): void
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

        $vcsFactory = $this->createStub(VcsFactory::class);
        $installationTokens = $this->createStub(InstallationTokens::class);
        $deploymentsFactory = fn () => null;

        $this->callHandler('handlePullRequestEvent', [
            'action' => 'closed',
            'repositoryId' => '5',
            'pullRequestNumber' => 5,
            'external' => true,
        ], $vcsFactory, $installationTokens, $dbForPlatform, $authorization, fn () => null, [], $deploymentsFactory);
    }

    private function callHandler(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($this->handler, $method))->invoke($this->handler, ...$arguments);
    }
}
