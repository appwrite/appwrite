<?php

declare(strict_types=1);

namespace Tests\Unit\Filter;

use Appwrite\Filter\BranchDomain as BranchDomainFilter;
use Appwrite\Platform\Modules\Compute\Base;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;

final class BranchDomainTest extends TestCase
{
    public function testBranchDomain(): void
    {
        $filter = new BranchDomainFilter();

        // Branch name with slash
        $domain = $filter->apply([
            'branch' => 'feature/test',
            'resourceId' => 'site123',
            'projectId' => 'proj456',
            'sitesDomain' => 'appwrite.network'
        ]);
        $this->assertStringNotContainsString('/', (string) $domain);
        $this->assertStringStartsWith('branch-feature-test-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        // Branch domain consistency
        $domain2 = $filter->apply([
            'branch' => 'feature/test',
            'resourceId' => 'site123',
            'projectId' => 'proj456',
            'sitesDomain' => 'appwrite.network'
        ]);
        $this->assertEquals($domain, $domain);

        // Different resources should produce different domains
        $domain2 = $filter->apply([
            'branch' => 'feature/test',
            'resourceId' => 'site789',
            'projectId' => 'proj456',
            'sitesDomain' => 'appwrite.network'
        ]);
        $this->assertNotEquals($domain, $domain2);

        // Different projects should produce different domains
        $domain2 = $filter->apply([
            'branch' => 'feature/test',
            'resourceId' => 'site123',
            'projectId' => 'proj789',
            'sitesDomain' => 'appwrite.network'
        ]);
        $this->assertNotEquals($domain, $domain2);

        // Some real-world branch names
        $domain = $filter->apply([
            'branch' => 'feature/SER-1234',
            'resourceId' => 'site123',
            'projectId' => 'proj456',
            'sitesDomain' => 'appwrite.network'
        ]);
        $this->assertStringStartsWith('branch-feature-ser-1234-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $domain = $filter->apply([
            'branch' => 'bugfix/fix-login',
            'resourceId' => 'site123',
            'projectId' => 'proj456',
            'sitesDomain' => 'appwrite.network'
        ]);
        $this->assertStringStartsWith('branch-bugfix-fix-login-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $domain = $filter->apply([
            'branch' => 'hotfix/v1.2.3',
            'resourceId' => 'site123',
            'projectId' => 'proj456',
            'sitesDomain' => 'appwrite.network'
        ]);
        $this->assertStringStartsWith('branch-hotfix-v1-2-3-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $domain = $filter->apply([
            'branch' => 'release/2024.01',
            'resourceId' => 'site123',
            'projectId' => 'proj456',
            'sitesDomain' => 'appwrite.network'
        ]);
        $this->assertStringStartsWith('branch-release-2024-01-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $domain = $filter->apply([
            'branch' => 'user/john/experiment',
            'resourceId' => 'site123',
            'projectId' => 'proj456',
            'sitesDomain' => 'appwrite.network'
        ]);
        $this->assertStringStartsWith('branch-user-john-experi-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $domain = $filter->apply([
            'branch' => 'dependabot/npm_and_yarn/lodash-4.17.21',
            'resourceId' => 'site123',
            'projectId' => 'proj456',
            'sitesDomain' => 'appwrite.network'
        ]);
        $this->assertStringStartsWith('branch-dependabot-npm-a-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);
    }

    public function testResourceHashSeparatesProjectAndResource(): void
    {
        $filter = new BranchDomainFilter();

        $first = $filter->apply(['branch' => 'main', 'resourceId' => 'site1', 'projectId' => '23', 'sitesDomain' => 'appwrite.network']);
        $second = $filter->apply(['branch' => 'main', 'resourceId' => 'site12', 'projectId' => '3', 'sitesDomain' => 'appwrite.network']);

        $this->assertNotSame($first, $second);
        $this->assertSame('branch-main-' . \substr(\hash('sha256', '23:site1'), 0, BranchDomainFilter::RESOURCE_HASH_LENGTH) . '.appwrite.network', $first);
    }

    public function testLegacyDomainIsUnchanged(): void
    {
        $domain = (new BranchDomainFilter())->apply([
            'branch' => 'feature/login',
            'resourceId' => 'site123',
            'projectId' => 'proj456',
            'sitesDomain' => 'appwrite.network',
            'legacy' => true,
        ]);

        $this->assertSame('branch-feature-login-4b08480.appwrite.network', $domain);
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

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturnCallback(fn (string $collection, string $id) => $id === \md5($legacy)
            ? new Document(['$id' => $id, ...self::OWNER])
            : new Document());

        $this->assertSame($legacy, Base::branchDomain(...[...$this->preview(), 'dbForPlatform' => $dbForPlatform]));
    }

    public function testNewBranchGetsTheWiderHash(): void
    {
        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturn(new Document());

        $this->assertStringEndsWith('-9cff6d18ba9545bf.appwrite.network', Base::branchDomain(...[...$this->preview(), 'dbForPlatform' => $dbForPlatform]));
    }

    /**
     * @return \Iterator<string, array{array<string, string>, bool}>
     */
    public static function rules(): \Iterator
    {
        yield 'this branch' => [self::OWNER, true];
        yield 'another project' => [[...self::OWNER, 'projectInternalId' => '9'], false];
        yield 'another site' => [[...self::OWNER, 'deploymentResourceInternalId' => '9'], false];
        yield 'another branch' => [[...self::OWNER, 'deploymentVcsProviderBranch' => 'feature-login'], false];
        yield 'a function' => [[...self::OWNER, 'deploymentResourceType' => 'function'], false];
    }

    /**
     * @param array<string, string> $owner
     */
    #[DataProvider('rules')]
    public function testOnlyTheBranchOwnerHoldsItsRule(array $owner, bool $expected): void
    {
        $preview = $this->preview();

        $this->assertSame($expected, Base::ownsBranchRule(new Document(['$id' => 'rule', ...$owner]), $preview['project'], $preview['site'], $preview['branch']));
        $this->assertFalse(Base::ownsBranchRule(new Document(), $preview['project'], $preview['site'], $preview['branch']));
    }

    private const array OWNER = [
        'projectInternalId' => '1',
        'deploymentResourceType' => 'site',
        'deploymentResourceInternalId' => '2',
        'deploymentVcsProviderBranch' => 'feature/login',
    ];

    /**
     * @return array{project: Document, site: Document, branch: string, sitesDomain: string}
     */
    private function preview(): array
    {
        return [
            'project' => new Document(['$id' => 'proj456', '$sequence' => '1']),
            'site' => new Document(['$id' => 'site123', '$sequence' => '2', '$collection' => 'sites']),
            'branch' => 'feature/login',
            'sitesDomain' => 'appwrite.network',
        ];
    }
}
