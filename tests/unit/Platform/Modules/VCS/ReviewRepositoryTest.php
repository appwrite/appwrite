<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\VCS;

use Appwrite\Platform\Modules\VCS\Http\GitHub\Reviews;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\VCS\Adapter\Git;

/**
 * A delivery authenticated only by an instance-wide webhook secret names no particular
 * repository, so the review job's repository comes from what the project connected.
 */
final class ReviewRepositoryTest extends TestCase
{
    /**
     * A forged delivery pairs the connected repository's id with another owner and name.
     */
    private const FORGED = [
        'action' => 'opened',
        'repositoryId' => '42',
        'installationId' => 'someone-else',
        'owner' => 'attacker',
        'repositoryName' => 'their-repo',
        'pullRequestNumber' => 7,
    ];

    public function testGitLabDeliveryTakesTheConnectedRepository(): void
    {
        $vcs = $this->createMock(Git::class);
        $vcs->expects($this->once())->method('getRepositoryName')->with('42')->willReturn('app');
        $vcs->expects($this->once())->method('getOwnerName')->with('installation-1', 42)->willReturn('victim/group');

        $delivery = $this->withStoredRepository('gitlab', self::FORGED, '42', $vcs);

        $this->assertSame('42', $delivery['repositoryId']);
        $this->assertSame('installation-1', $delivery['installationId']);
        $this->assertSame('victim/group', $delivery['owner']);
        $this->assertSame('app', $delivery['repositoryName']);
        // The rest of the delivery is left as it came.
        $this->assertSame(7, $delivery['pullRequestNumber']);
    }

    public function testBitbucketOwnerIsTheWorkspaceOfTheConnectedRepository(): void
    {
        $vcs = $this->createMock(Git::class);
        $vcs->expects($this->once())->method('getRepositoryName')->with('victim%2Fapp')->willReturn('app');
        $vcs->expects($this->never())->method('getOwnerName');

        $delivery = $this->withStoredRepository('bitbucket', [...self::FORGED, 'repositoryId' => 'victim%2Fapp'], 'victim%2Fapp', $vcs);

        $this->assertSame('victim', $delivery['owner']);
        $this->assertSame('app', $delivery['repositoryName']);
        $this->assertSame('victim%2Fapp', $delivery['repositoryId']);
    }

    public function testRepositoryTheProviderCannotNameQueuesNothing(): void
    {
        $vcs = $this->createStub(Git::class);
        $vcs->method('getRepositoryName')->willReturn('');
        $vcs->method('getOwnerName')->willReturn('victim');

        $this->expectException(\Exception::class);
        $this->withStoredRepository('gitea', self::FORGED, '42', $vcs);
    }

    public function testAppProviderDeliveryIsKept(): void
    {
        $vcs = $this->createMock(Git::class);
        $vcs->expects($this->never())->method('getRepositoryName');
        $vcs->expects($this->never())->method('getOwnerName');

        $this->assertSame(self::FORGED, $this->withStoredRepository('github', self::FORGED, '42', $vcs, refreshes: false));
    }

    /**
     * @param array<string, mixed> $parsedPayload
     * @return array<string, mixed>
     */
    private function withStoredRepository(string $provider, array $parsedPayload, string $providerRepositoryId, Git $vcs, bool $refreshes = true): array
    {
        $installation = new Document(['$id' => 'installation', 'providerInstallationId' => 'installation-1', 'provider' => $provider]);
        $repository = new Document(['$id' => 'repository', 'providerRepositoryId' => $providerRepositoryId, 'installationId' => 'installation']);

        $vcsFactory = $this->createStub(VcsFactory::class);
        $vcsFactory->method('fromInstallation')->willReturn($vcs);

        $installationTokens = $this->createMock(InstallationTokens::class);
        $installationTokens->expects($refreshes ? $this->once() : $this->never())->method('refreshForInstallation')->willReturnArgument(0);

        $reviews = new class () {
            use Reviews;

            protected function getReviewerHandle(Document $installation): string
            {
                return '';
            }

            protected function getReviewerLogin(Document $installation): string
            {
                return '';
            }

            protected function getCommentAuthorAssociation(array $parsedPayload, Document $installation, VcsFactory $vcsFactory, InstallationTokens $installationTokens, Database $dbForPlatform): string
            {
                return '';
            }

            protected function beforeQueueReview(Document $project, Document $repository, Database $dbForPlatform, Authorization $authorization): void
            {
            }

            private function isProjectInCurrentRegion(Document $project): bool
            {
                return true;
            }
        };

        $method = new \ReflectionMethod($reviews, 'withStoredRepository');

        return $method->invoke($reviews, $provider, $parsedPayload, $repository, $installation, $vcsFactory, $installationTokens, $this->createStub(Database::class), new Authorization());
    }
}
