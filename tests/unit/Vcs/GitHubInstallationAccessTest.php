<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\GitHubInstallationAccess;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class GitHubInstallationAccessTest extends TestCase
{
    public function testUserInstallsOnTheirOwnAccount(): void
    {
        $github = new FakeGitHub(
            user: ['id' => 7],
            installations: [['id' => 100, 'account' => ['id' => 7, 'type' => 'User']]],
        );

        $this->assertTrue((new GitHubInstallationAccess($github, 'token'))->administers('100', 3));
    }

    public function testCollaboratorCannotAttachAnotherUsersInstallation(): void
    {
        $github = new FakeGitHub(
            user: ['id' => 7],
            installations: [['id' => 100, 'account' => ['id' => 8, 'type' => 'User']]],
            repositories: ['100' => [$this->repository(admin: true)]],
        );

        $this->assertFalse((new GitHubInstallationAccess($github, 'token'))->administers('100', 1));
    }

    public function testOrganizationAdminOfEveryRepositoryMayAttach(): void
    {
        $github = new FakeGitHub(
            user: ['id' => 7],
            installations: [['id' => 100, 'account' => ['id' => 9, 'type' => 'Organization']]],
            repositories: ['100' => [$this->repository(admin: true), $this->repository(admin: true)]],
        );

        $this->assertTrue((new GitHubInstallationAccess($github, 'token'))->administers('100', 2));
    }

    public function testOrganizationReaderCannotAttach(): void
    {
        $github = new FakeGitHub(
            user: ['id' => 7],
            installations: [['id' => 100, 'account' => ['id' => 9, 'type' => 'Organization']]],
            repositories: ['100' => [$this->repository(admin: true), $this->repository(admin: false)]],
        );

        $this->assertFalse((new GitHubInstallationAccess($github, 'token'))->administers('100', 2));
    }

    public function testOrganizationMemberWhoSeesOnlySomeRepositoriesCannotAttach(): void
    {
        $github = new FakeGitHub(
            user: ['id' => 7],
            installations: [['id' => 100, 'account' => ['id' => 9, 'type' => 'Organization']]],
            repositories: ['100' => [$this->repository(admin: true)]],
        );

        $this->assertFalse((new GitHubInstallationAccess($github, 'token'))->administers('100', 5));
    }

    public function testInstallationNotListedForTheUserIsRejected(): void
    {
        $github = new FakeGitHub(
            user: ['id' => 7],
            installations: [['id' => 200, 'account' => ['id' => 7, 'type' => 'User']]],
        );

        $this->assertFalse((new GitHubInstallationAccess($github, 'token'))->administers('100', 1));
    }

    public function testGitHubErrorIsRejected(): void
    {
        $github = new FakeGitHub(user: ['id' => 7], installations: [], failing: true);

        $this->assertFalse((new GitHubInstallationAccess($github, 'token'))->administers('100', 1));
    }

    public function testRequestsCarryTheUserToken(): void
    {
        $github = new FakeGitHub(
            user: ['id' => 7],
            installations: [['id' => 100, 'account' => ['id' => 7, 'type' => 'User']]],
        );

        (new GitHubInstallationAccess($github, 'user-token'))->administers('100', 1);

        $this->assertNotEmpty($github->authorizations);
        $this->assertSame(['Bearer user-token'], \array_values(\array_unique($github->authorizations)));
    }

    /**
     * @return array<string, mixed>
     */
    private function repository(bool $admin): array
    {
        return ['permissions' => ['admin' => $admin, 'push' => true, 'pull' => true]];
    }
}

/**
 * Answers the GitHub user endpoints the access check reads, one page each.
 */
final class FakeGitHub implements ClientInterface
{
    /**
     * @var array<string>
     */
    public array $authorizations = [];

    /**
     * @param array<string, mixed> $user
     * @param array<array<string, mixed>> $installations
     * @param array<string, array<array<string, mixed>>> $repositories
     */
    public function __construct(
        private readonly array $user,
        private readonly array $installations,
        private readonly array $repositories = [],
        private readonly bool $failing = false,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->authorizations[] = $request->getHeaderLine('Authorization');

        if ($this->failing) {
            return new Response(500, body: new Stream('{}'));
        }

        $path = $request->getUri()->getPath();
        $segments = \explode('/', \trim($path, '/'));
        $installationId = \count($segments) === 4
            && $segments[0] === 'user'
            && $segments[1] === 'installations'
            && $segments[3] === 'repositories'
                ? $segments[2]
                : null;

        $body = match (true) {
            $path === '/user' => $this->user,
            $path === '/user/installations' => ['total_count' => \count($this->installations), 'installations' => $this->installations],
            $installationId !== null => [
                'total_count' => \count($this->repositories[$installationId] ?? []),
                'repositories' => $this->repositories[$installationId] ?? [],
            ],
            default => null,
        };

        if ($body === null) {
            return new Response(404, body: new Stream('{}'));
        }

        return new Response(200, body: new Stream((string) \json_encode($body)));
    }
}
