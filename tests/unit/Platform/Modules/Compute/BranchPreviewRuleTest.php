<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Compute;

use Appwrite\Bus\Events\RuleUpdated;
use Appwrite\Platform\Modules\Compute\Base;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;

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
