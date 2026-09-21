<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\VCS\Http\GitHub;

use Appwrite\Platform\Modules\VCS\Http\GitHub\Deployment;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\VCS\Adapter\Git;

require_once __DIR__ . '/../../../../../../../app/init.php';

final class DeploymentTest extends TestCase
{
    /**
     * A function or site is deleted before the deletes worker removes its repository row. A push
     * arriving in that window resolved an empty resource, queried deployments by a null sequence
     * and failed the whole webhook with a 500 (Sentry CLOUD-3QWM). The repository must be skipped.
     */
    public function testPushForDeletedResourceIsSkipped(): void
    {
        $previous = Config::getParam('pools-database', []);
        Config::setParam('pools-database', ['database_db_main']);

        $project = new Document([
            '$id' => 'project1',
            '$sequence' => '1',
            'database' => 'database_db_main',
        ]);
        $repository = new Document([
            '$id' => 'repository1',
            'projectId' => 'project1',
            'resourceId' => 'function1',
            'resourceType' => 'function',
        ]);

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturn($project);

        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDocument')->willReturn(new Document());
        $dbForProject->expects($this->never())->method('findOne');
        $dbForProject->expects($this->never())->method('createDocument');

        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $deploymentsFactory = function (): never {
            $this->fail('No deployment must be created for a deleted resource');
        };

        $handler = new class () {
            use Deployment;

            public function run(array $arguments): void
            {
                $this->createGitDeployments(...$arguments);
            }
        };

        try {
            $handler->run([
                $this->createStub(Git::class),
                'installation1',
                [$repository],
                'main',
                'https://github.com/owner/repo/tree/main',
                'repo',
                'https://github.com/owner/repo',
                'owner',
                'abc123',
                'author',
                'https://github.com/author',
                'commit message',
                'https://github.com/owner/repo/commit/abc123',
                '',
                [],
                false,
                $dbForPlatform,
                $authorization,
                $this->createStub(Bus::class),
                static fn (Document $project): Database => $dbForProject,
                [],
                $deploymentsFactory,
            ]);
        } finally {
            Config::setParam('pools-database', $previous);
        }
    }
}
