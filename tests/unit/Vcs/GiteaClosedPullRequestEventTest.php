<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Platform\Modules\VCS\Http\Gitea\Events\Create;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class GiteaClosedPullRequestEventTest extends TestCase
{
    private Create $handler;

    public function setUp(): void
    {
        $this->handler = new Create();
    }

    public function testClosedEventOnlyUpdatesGiteaRepositories(): void
    {
        // A GitHub repo and a Gitea repo can share the same numeric
        // providerRepositoryId since each is scoped to its own server.
        $giteaRepository = new Document([
            '$id' => 'repo-gitea',
            'installationId' => 'install-gitea',
            'providerRepositoryId' => '5',
            'providerPullRequestIds' => [5],
        ]);

        $githubRepository = new Document([
            '$id' => 'repo-github',
            'installationId' => 'install-github',
            'providerRepositoryId' => '5',
            'providerPullRequestIds' => [5],
        ]);

        $giteaInstallation = new Document(['$id' => 'install-gitea', 'provider' => 'gitea']);
        $githubInstallation = new Document(['$id' => 'install-github', 'provider' => 'github']);

        $dbForPlatform = $this->createMock(Database::class);
        $authorization = $this->createStub(Authorization::class);

        $dbForPlatform->method('find')->willReturn([$giteaRepository, $githubRepository]);

        $dbForPlatform->method('getDocument')
            ->willReturnCallback(fn (string $collection, string $id): Document => match ($id) {
                'install-gitea' => $giteaInstallation,
                'install-github' => $githubInstallation,
                default => new Document(),
            });

        $authorization->method('skip')->willReturnCallback(fn (callable $fn): mixed => $fn());

        $dbForPlatform->expects($this->once())
            ->method('updateDocument')
            ->with('repositories', 'repo-gitea', $this->callback(function (Document $doc) {
                $this->assertSame([], array_values($doc->getAttribute('providerPullRequestIds')));
                return true;
            }));

        $publisherForBuilds = $this->createStub(BuildPublisher::class);

        $this->callHandler('handlePullRequestEvent', [
            'action' => 'closed',
            'repositoryId' => '5',
            'pullRequestNumber' => 5,
            'external' => true,
        ], fn () => null, $dbForPlatform, $authorization, $publisherForBuilds, fn () => null, []);
    }

    private function callHandler(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($this->handler, $method))->invoke($this->handler, ...$arguments);
    }
}
