<?php

namespace Tests\Unit\Filter;

use Appwrite\Filter\Filter;
use Appwrite\Filter\Adapter\BranchDomain;
use PHPUnit\Framework\TestCase;

class FilterTest extends TestCase
{
    public function testBranchDomain(): void
    {
        $filter = new Filter([new BranchDomain()]);

        // Branch name with slash
        $success = $filter
            ->setInput([
                'branch' => 'feature/test',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertTrue($success);
        $domain = $filter->getOutput();
        $this->assertStringNotContainsString('/', $domain);
        $this->assertStringStartsWith('branch-feature-test-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        // Branch domain consistency
        $success = $filter
            ->setInput([
                'branch' => 'feature/test',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertTrue($success);
        $domain2 = $filter->getOutput();
        $this->assertEquals($domain, $domain2);

        // Different resources should produce different domains
        $success = $filter
            ->setInput([
                'branch' => 'feature/test',
                'resourceId' => 'site789',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertTrue($success);
        $domain2 = $filter->getOutput();
        $this->assertNotEquals($domain, $domain2);

        // Different projects should produce different domains
        $success = $filter
            ->setInput([
                'branch' => 'feature/test',
                'resourceId' => 'site123',
                'projectId' => 'proj789',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertTrue($success);
        $domain2 = $filter->getOutput();
        $this->assertNotEquals($domain, $domain2);

        // Some real-world branch names
        $success = $filter
            ->setInput([
                'branch' => 'feature/SER-1234',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertTrue($success);
        $domain = $filter->getOutput();
        $this->assertStringStartsWith('branch-feature-ser-1234-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $success = $filter
            ->setInput([
                'branch' => 'bugfix/fix-login',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertTrue($success);
        $domain = $filter->getOutput();
        $this->assertStringStartsWith('branch-bugfix-fix-login-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $success = $filter
            ->setInput([
                'branch' => 'hotfix/v1.2.3',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertTrue($success);
        $domain = $filter->getOutput();
        $this->assertStringStartsWith('branch-hotfix-v1-2-3-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $success = $filter
            ->setInput([
                'branch' => 'release/2024.01',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertTrue($success);
        $domain = $filter->getOutput();
        $this->assertStringStartsWith('branch-release-2024-01-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $success = $filter
            ->setInput([
                'branch' => 'user/john/experiment',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertTrue($success);
        $domain = $filter->getOutput();
        $this->assertStringStartsWith('branch-user-john-experi-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $success = $filter
            ->setInput([
                'branch' => 'dependabot/npm_and_yarn/lodash-4.17.21',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertTrue($success);
        $domain = $filter->getOutput();
        $this->assertStringStartsWith('branch-dependabot-npm-a-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        // Invalid inputs
        $success = $filter
            ->setInput([
                'branch' => '',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertFalse($success);

        $success = $filter
            ->setInput([
                'branch' => 'feature/test',
                'resourceId' => '',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertFalse($success);

        $success = $filter
            ->setInput([
                'branch' => 'feature/test',
                'resourceId' => 'site123',
                'projectId' => '',
                'sitesDomain' => 'appwrite.network'
            ])
            ->filter();
        $this->assertFalse($success);
        
        $success = $filter
            ->setInput([
                'branch' => 'feature/test',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => ''
            ])
            ->filter();
        $this->assertFalse($success);
    }
}