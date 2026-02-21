<?php

namespace Tests\Unit\Filter;

use Appwrite\Filter\BranchDomain as BranchDomainFilter;
use PHPUnit\Framework\TestCase;

class BranchDomainTest extends TestCase
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
        $this->assertStringNotContainsString('/', $domain);
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
}
