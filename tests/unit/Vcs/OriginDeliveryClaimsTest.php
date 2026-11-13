<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Platform\Modules\VCS\Http\Origin\Events\Create;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;

/**
 * Origin redelivers anything it got an error for, so each repository claims a
 * delivery once and gives the claim back only when it failed without deploying.
 */
final class OriginDeliveryClaimsTest extends TestCase
{
    /** @var array<string, true> Claims held in the platform database, by id */
    private array $claims = [];

    /** @var array<string, Document> Projects by id */
    private array $projects = [];

    /** Whether the project database holds a deployment of the pushed commit */
    private bool $deployed = false;

    private mixed $previousPools;

    protected function setUp(): void
    {
        $this->previousPools = Config::getParam('pools-database', []);
        Config::setParam('pools-database', ['db_main']);

        $this->projects = [
            'here' => new Document(['$id' => 'here', 'database' => 'db_main']),
            'elsewhere' => new Document(['$id' => 'elsewhere', 'database' => 'db_other']),
        ];
    }

    protected function tearDown(): void
    {
        Config::setParam('pools-database', $this->previousPools);
    }

    public function testARetryDeploysOnlyTheRepositoryThatFailedWithoutDeploying(): void
    {
        $repositories = [$this->repository('a'), $this->repository('b')];
        $deployed = [];

        $this->expectFailure($repositories, 'push:main', function (Document $repository) use (&$deployed): void {
            if ($repository->getId() === 'b') {
                throw new \RuntimeException('Could not reach Origin');
            }
            $deployed[] = $repository->getId();
        });

        $this->deployOnce($repositories, 'push:main', function (Document $repository) use (&$deployed): void {
            $deployed[] = $repository->getId();
        });

        $this->assertSame(['a', 'b'], $deployed);
    }

    public function testAFailureAfterTheDeploymentExistsKeepsTheClaim(): void
    {
        $repositories = [$this->repository('a')];
        $attempts = 0;

        $this->expectFailure($repositories, 'push:main', function () use (&$attempts): void {
            $attempts++;
            $this->deployed = true;
            throw new \RuntimeException('Could not write preview rules');
        });

        $this->deployOnce($repositories, 'push:main', function () use (&$attempts): void {
            $attempts++;
        });

        $this->assertSame(1, $attempts, 'A retry must not build a commit that already has a deployment');
    }

    public function testEachBranchOfOneDeliveryIsClaimedOnItsOwn(): void
    {
        $repositories = [$this->repository('a')];
        $branches = [];

        foreach (['main', 'staging'] as $branch) {
            $this->deployOnce($repositories, 'push:' . $branch, function () use (&$branches, $branch): void {
                $branches[] = $branch;
            });
        }

        $this->assertSame(['main', 'staging'], $branches);
    }

    public function testARepositoryOfAnotherRegionIsLeftForThatRegion(): void
    {
        $deployed = [];

        $this->deployOnce([$this->repository('a', 'elsewhere')], 'push:main', function (Document $repository) use (&$deployed): void {
            $deployed[] = $repository->getId();
        });

        $this->assertSame([], $deployed);
        $this->assertSame([], $this->claims, 'A claim here would make the other region skip the repository');
    }

    private function repository(string $id, string $projectId = 'here'): Document
    {
        return new Document([
            '$id' => $id,
            'projectId' => $projectId,
            'resourceId' => 'resource-' . $id,
            'resourceType' => 'site',
        ]);
    }

    /**
     * @param array<Document> $repositories
     */
    private function expectFailure(array $repositories, string $subject, callable $deploy): void
    {
        try {
            $this->deployOnce($repositories, $subject, $deploy);
        } catch (\Throwable) {
            return;
        }

        $this->fail('A delivery a repository failed on must fail, so Origin delivers it again');
    }

    /**
     * @param array<Document> $repositories
     */
    private function deployOnce(array $repositories, string $subject, callable $deploy): void
    {
        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturnCallback(fn (string $collection, string $id): Document => $this->projects[$id] ?? new Document());
        $dbForPlatform->method('createDocument')->willReturnCallback(function (string $collection, Document $document): Document {
            if (isset($this->claims[$document->getId()])) {
                throw new Duplicate('Claimed');
            }
            $this->claims[$document->getId()] = true;

            return $document;
        });
        $dbForPlatform->method('deleteDocument')->willReturnCallback(function (string $collection, string $id): bool {
            unset($this->claims[$id]);

            return true;
        });

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('findOne')->willReturnCallback(fn (): Document => $this->deployed ? new Document(['$id' => 'deployment']) : new Document());

        $method = new \ReflectionMethod(Create::class, 'deployOnce');
        $method->invoke(
            (new \ReflectionClass(Create::class))->newInstanceWithoutConstructor(),
            $repositories,
            'msg_0123456789',
            $subject,
            'commit123',
            'main',
            $dbForPlatform,
            new Authorization(),
            fn (): Database => $dbForProject,
            $deploy,
        );
    }
}
