<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Functions\Workers;

use Appwrite\Platform\Modules\Functions\Workers\Jobs;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

final class ActivateRulesTest extends TestCase
{
    public function testActivateRebindsGeneratedBranchDomain(): void
    {
        $captured = [];
        $dbForPlatform = $this->platformDatabaseCapturing($captured);

        $this->activate($dbForPlatform, deploymentBranch: 'main');

        $branchQuery = $this->queryFor($captured, 'deploymentVcsProviderBranch');
        $this->assertInstanceOf(\Utopia\Database\Query::class, $branchQuery);
        $this->assertSame(['', 'main'], $branchQuery->getValues());

        $resourceType = $this->queryFor($captured, 'deploymentResourceType');
        $this->assertInstanceOf(\Utopia\Database\Query::class, $resourceType);
        $this->assertSame(['function'], $resourceType->getValues());
    }

    public function testActivateOfNonVcsDeploymentOnlyTouchesBranchAgnosticRules(): void
    {
        $captured = [];
        $dbForPlatform = $this->platformDatabaseCapturing($captured);

        $this->activate($dbForPlatform, deploymentBranch: '');

        $branchQuery = $this->queryFor($captured, 'deploymentVcsProviderBranch');
        $this->assertInstanceOf(\Utopia\Database\Query::class, $branchQuery);
        $this->assertSame([''], $branchQuery->getValues());
    }

    public function testActivateRepointsMatchedRuleAtTheNewDeployment(): void
    {
        $updated = [];
        $dispatched = 0;

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('forEach')->willReturnCallback(
            function (string $collection, callable $callback): void {
                $callback(new Document(['$id' => 'rule-branch', 'deploymentId' => '']));
            }
        );
        $dbForPlatform->method('updateDocument')->willReturnCallback(
            function (string $collection, string $id, Document $data) use (&$updated): Document {
                $updated[$id] = $data->getAttribute('deploymentId');

                return new Document(['$id' => $id, ...$data->getArrayCopy()]);
            }
        );

        $bus = $this->createStub(Bus::class);
        $bus->method('dispatch')->willReturnCallback(function () use (&$dispatched): void {
            $dispatched++;
        });

        $this->activate($dbForPlatform, deploymentBranch: 'main', bus: $bus);

        $this->assertArrayHasKey('rule-branch', $updated);
        $this->assertSame('dep-active', $updated['rule-branch']);
        $this->assertSame(1, $dispatched);
    }

    private function activate(Database $dbForPlatform, string $deploymentBranch, ?Bus $bus = null): void
    {
        $resource = new Document([
            '$id' => 'func-1',
            '$sequence' => '100',
            '$collection' => 'functions',
        ]);

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('updateDocument')->willReturn($resource);

        $project = new Document(['$id' => 'project-1', '$sequence' => '7']);
        $deployment = new Document([
            '$id' => 'dep-active',
            '$sequence' => '55',
            'providerBranch' => $deploymentBranch,
        ]);

        (new ActivateRulesTestJobs())->exposeActivate(
            $dbForProject,
            $dbForPlatform,
            $project,
            $resource,
            $deployment,
            $bus ?? $this->createStub(Bus::class),
        );
    }

    /**
     * @param array<Query> $captured
     */
    private function platformDatabaseCapturing(array &$captured): Database
    {
        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('forEach')->willReturnCallback(
            function (string $collection, callable $callback, array $queries = []) use (&$captured): void {
                $captured = $queries;
            }
        );

        return $dbForPlatform;
    }

    /**
     * @param array<Query> $queries
     */
    private function queryFor(array $queries, string $attribute): ?Query
    {
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_EQUAL && $query->getAttribute() === $attribute) {
                return $query;
            }
        }

        return null;
    }
}

final class ActivateRulesTestJobs extends Jobs
{
    public function exposeActivate(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Document $resource,
        Document $deployment,
        Bus $bus,
    ): void {
        $this->activate($dbForProject, $dbForPlatform, $project, $resource, $deployment, $bus);
    }
}
