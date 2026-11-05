<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Compute;

use Appwrite\Bus\Events\RuleUpdated;
use Appwrite\Filter\BranchDomain as BranchDomainFilter;
use Appwrite\Platform\Modules\Compute\Base;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;

final class BranchPreviewRuleTest extends TestCase
{
    public function testValidBranchDomainCreatesThePreviewRule(): void
    {
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->once())
            ->method('createDocument')
            ->with('rules', $this->callback(fn (Document $rule) => \str_starts_with($rule->getAttribute('domain'), 'branch-feature-login-')))
            ->willReturnArgument(1);
        $dbForPlatform->expects($this->once())->method('cursor');

        $this->activate($dbForPlatform, 'appwrite.network');
    }

    public function testInvalidBranchDomainSkipsOnlyThePreviewRule(): void
    {
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->never())->method('createDocument');
        $dbForPlatform->expects($this->never())->method('updateDocument');

        // Manual rules pinned to the branch still follow the new deployment.
        $dbForPlatform->expects($this->once())->method('cursor')->with('rules');

        // Deliberately not wrapped: the deployment must go ahead, so a throw
        // here is the defect this test covers.
        $this->activate($dbForPlatform, 'appwrite.network/path');
    }

    public function testManualBranchRulesFollowTheNewDeployment(): void
    {
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('cursor')->willReturnCallback(function (): \Generator {
            yield new Document(['$id' => 'manualRule']);
        });
        $dbForPlatform->expects($this->once())
            ->method('updateDocument')
            ->with('rules', 'manualRule', $this->callback(
                function (Document $update): bool {
                    $this->assertSame('deployment1', $update->getAttribute('deploymentId'));
                    $this->assertSame('3', $update->getAttribute('deploymentInternalId'));
                    return true;
                }
            ))
            ->willReturnCallback(fn (string $collection, string $id, Document $update) => new Document(['$id' => $id, ...$update->getArrayCopy()]));

        $bus = $this->createMock(Bus::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (RuleUpdated $event): void {
                $this->assertSame('manualRule', $event->rule['$id']);
            });

        $this->activate($dbForPlatform, 'appwrite.network/path', $bus);
    }

    public function testDuplicateRuleOfThisBranchFollowsTheNewDeployment(): void
    {
        $dbForPlatform = $this->duplicateRule(['projectInternalId' => '1', 'deploymentResourceType' => 'site', 'deploymentResourceInternalId' => '2', 'deploymentVcsProviderBranch' => 'feature/login']);
        $dbForPlatform->expects($this->once())
            ->method('updateDocument')
            ->with('rules', $this->anything(), $this->callback(function (Document $update): bool {
                $this->assertSame('deployment1', $update->getAttribute('deploymentId'));
                return true;
            }))
            ->willReturnCallback(fn (string $collection, string $id, Document $update) => new Document(['$id' => $id, ...$update->getArrayCopy()]));

        $this->activate($dbForPlatform, 'appwrite.network');
    }

    /**
     * @return \Iterator<string, array{array<string, string>}>
     */
    public static function foreignRules(): \Iterator
    {
        yield 'another project' => [['projectInternalId' => '9', 'deploymentResourceType' => 'site', 'deploymentResourceInternalId' => '2', 'deploymentVcsProviderBranch' => 'feature/login']];
        yield 'another site' => [['projectInternalId' => '1', 'deploymentResourceType' => 'site', 'deploymentResourceInternalId' => '9', 'deploymentVcsProviderBranch' => 'feature/login']];
        yield 'another branch' => [['projectInternalId' => '1', 'deploymentResourceType' => 'site', 'deploymentResourceInternalId' => '2', 'deploymentVcsProviderBranch' => 'feature-login']];
        yield 'a function' => [['projectInternalId' => '1', 'deploymentResourceType' => 'function', 'deploymentResourceInternalId' => '2', 'deploymentVcsProviderBranch' => 'feature/login']];
    }

    /**
     * @param array<string, string> $owner
     */
    #[DataProvider('foreignRules')]
    public function testDuplicateRuleOfAnotherResourceIsLeftAlone(array $owner): void
    {
        $dbForPlatform = $this->duplicateRule($owner);
        $dbForPlatform->expects($this->never())->method('updateDocument');

        $this->activate($dbForPlatform, 'appwrite.network');
    }

    public function testBranchPreviewedBeforeTheWiderHashKeepsItsDomain(): void
    {
        $legacy = (new BranchDomainFilter())->apply([
            'branch' => 'feature/login',
            'resourceId' => 'site123',
            'projectId' => 'proj456',
            'sitesDomain' => 'appwrite.network',
            'legacy' => true,
        ]);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('getDocument')->willReturnCallback(fn (string $collection, string $id) => $id === md5($legacy)
            ? new Document(['$id' => $id, 'projectInternalId' => '1', 'deploymentResourceType' => 'site', 'deploymentResourceInternalId' => '2', 'deploymentVcsProviderBranch' => 'feature/login'])
            : new Document());
        $dbForPlatform->expects($this->once())
            ->method('createDocument')
            ->with('rules', $this->callback(function (Document $rule) use ($legacy): bool {
                $this->assertSame($legacy, $rule->getAttribute('domain'));
                return true;
            }))
            ->willThrowException(new Duplicate());
        $dbForPlatform->expects($this->once())
            ->method('updateDocument')
            ->with('rules', md5($legacy))
            ->willReturnCallback(fn (string $collection, string $id, Document $update) => new Document(['$id' => $id, ...$update->getArrayCopy()]));

        $this->activate($dbForPlatform, 'appwrite.network');
    }

    public function testNewBranchGetsTheWiderHash(): void
    {
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('getDocument')->willReturn(new Document());
        $dbForPlatform->expects($this->once())
            ->method('createDocument')
            ->with('rules', $this->callback(fn (Document $rule) => \str_ends_with($rule->getAttribute('domain'), '-9cff6d18ba9545bf.appwrite.network')))
            ->willReturnArgument(1);

        $this->activate($dbForPlatform, 'appwrite.network');
    }

    /**
     * @param array<string, string> $owner
     */
    private function duplicateRule(array $owner): Database&MockObject
    {
        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('createDocument')->willThrowException(new Duplicate());
        $dbForPlatform->method('getDocument')->willReturnCallback(
            fn (string $collection, string $id) => new Document(['$id' => $id, ...$owner])
        );

        return $dbForPlatform;
    }

    private function activate(Database $dbForPlatform, string $sitesDomain, ?Bus $bus = null): void
    {
        Base::activateBranchPreviewRule(
            new Document(['$id' => 'proj456', '$sequence' => '1', 'region' => 'fra']),
            new Document(['$id' => 'site123', '$sequence' => '2', '$collection' => 'sites']),
            new Document([
                '$id' => 'deployment1',
                '$sequence' => '3',
                'providerBranch' => 'feature/login',
                'installationId' => 'installation1',
            ]),
            $dbForPlatform,
            $bus ?? $this->createStub(Bus::class),
            $sitesDomain,
        );
    }
}
