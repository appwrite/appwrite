<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Functions\Workers;

use Appwrite\Platform\Modules\Functions\Workers\Jobs;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Query\Method;

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

    public function testActivateOfTemplateOnlyTouchesBranchAgnosticRules(): void
    {
        $captured = [];
        $dbForPlatform = $this->platformDatabaseCapturing($captured);

        $this->activate($dbForPlatform, deploymentBranch: 'main', installationId: '');

        $branchQuery = $this->queryFor($captured, 'deploymentVcsProviderBranch');
        $this->assertInstanceOf(Query::class, $branchQuery);
        $this->assertSame([''], $branchQuery->getValues(), 'a template reuses providerBranch for its resolved ref and must not repoint a rule pinned to that branch');
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

    public function testActivateBranchRuleTargetsOnlyThatBranch(): void
    {
        $rebound = [];
        $dbForPlatform = $this->platformDatabaseApplyingQueries($rebound);

        $this->activateBranchRule($dbForPlatform, deploymentBranch: 'feature');

        $this->assertSame(['rule-feature'], $rebound);
    }

    public function testActivateBranchRuleSkipsTemplate(): void
    {
        $rebound = [];
        $dbForPlatform = $this->platformDatabaseApplyingQueries($rebound);

        $this->activateBranchRule($dbForPlatform, deploymentBranch: 'main', installationId: '');

        $this->assertSame([], $rebound, 'a template deployment reuses providerBranch for its resolved ref and must not repoint a rule pinned to that branch');
    }

    private function activate(Database $dbForPlatform, string $deploymentBranch, ?Bus $bus = null, string $installationId = 'inst-1'): void
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
            'installationId' => $installationId,
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

    private function activateBranchRule(Database $dbForPlatform, string $deploymentBranch, string $installationId = 'inst-1'): void
    {
        (new ActivateRulesTestJobs())->exposeActivateBranchRule(
            $dbForPlatform,
            new Document(['$id' => 'project-1', '$sequence' => '7']),
            new Document(['$id' => 'func-1', '$sequence' => '100', '$collection' => 'functions']),
            new Document([
                '$id' => 'dep-branch',
                '$sequence' => '56',
                'providerBranch' => $deploymentBranch,
                'installationId' => $installationId,
            ]),
            $this->createStub(Bus::class),
        );
    }

    /**
     * Unlike platformDatabaseCapturing(), this evaluates the queries the worker
     * builds against real rule rows, so an assertion is about which rules were
     * repointed rather than about the shape of a Query object.
     *
     * @param array<string> $rebound
     */
    private function platformDatabaseApplyingQueries(array &$rebound): Database
    {
        $rules = [
            new Document(['$id' => 'rule-agnostic', '$sequence' => '41', 'projectInternalId' => '7', 'type' => 'deployment', 'deploymentResourceInternalId' => '100', 'deploymentResourceType' => 'function', 'trigger' => 'manual', 'deploymentVcsProviderBranch' => '']),
            new Document(['$id' => 'rule-main', '$sequence' => '42', 'projectInternalId' => '7', 'type' => 'deployment', 'deploymentResourceInternalId' => '100', 'deploymentResourceType' => 'function', 'trigger' => 'manual', 'deploymentVcsProviderBranch' => 'main']),
            new Document(['$id' => 'rule-feature', '$sequence' => '43', 'projectInternalId' => '7', 'type' => 'deployment', 'deploymentResourceInternalId' => '100', 'deploymentResourceType' => 'function', 'trigger' => 'manual', 'deploymentVcsProviderBranch' => 'feature']),
        ];

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('updateDocument')->willReturnCallback(
            function (string $collection, string $id, Document $document) use (&$rebound): Document {
                $rebound[] = $id;

                return new Document(['$id' => $id, ...$document->getArrayCopy()]);
            }
        );
        $dbForPlatform->method('forEach')->willReturnCallback(
            static function (string $collection, callable $callback, array $queries = []) use ($rules): void {
                foreach ($rules as $rule) {
                    foreach ($queries as $query) {
                        if (!\in_array($rule->getAttribute($query->getAttribute()), $query->getValues(), true)) {
                            continue 2;
                        }
                    }

                    $callback($rule);
                }
            }
        );

        return $dbForPlatform;
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
            if ($query->getMethod() === Method::Equal && $query->getAttribute() === $attribute) {
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

    public function exposeActivateBranchRule(
        Database $dbForPlatform,
        Document $project,
        Document $resource,
        Document $deployment,
        Bus $bus,
    ): void {
        $this->activateBranchRule($dbForPlatform, $project, $resource, $deployment, $bus);
    }
}
