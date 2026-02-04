<?php

namespace Tests\Unit\Transformation;

use Appwrite\Transformation\Adapter\BranchDomain;
use Appwrite\Transformation\Adapter\Mock;
use Appwrite\Transformation\Adapter\Preview;
use Appwrite\Transformation\Transformation;
use PHPUnit\Framework\TestCase;

class TransformationTest extends TestCase
{
    public function testPreview(): void
    {
        $input = "Hello world";

        $transformer = new Transformation([new Preview()]);
        $transformer->addAdapter(new Mock());

        $transformer->setInput($input);
        $transformer->setTraits([]);

        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => true]);
        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => true, 'content-type' => 'text/plain']);
        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => true, 'content-type' => 'tExT/HtML']);
        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => false, 'content-type' => 'text/plain, text/html; charset=utf-8']);
        $this->assertFalse($transformer->transform());

        $transformer->setTraits(['mock' => true, 'content-type' => 'text/plain, text/html; charset=utf-8']);
        $this->assertTrue($transformer->transform());

        $this->assertStringContainsString("Hello world", $transformer->getOutput());
        $this->assertStringContainsString("Preview by", $transformer->getOutput());
        $this->assertStringContainsString("Mock:", $transformer->getOutput());
    }

    public function testBranchDomain(): void
    {
        $transformer = new Transformation([new BranchDomain()]);

        // Branch name with slash
        $transformer
            ->setInput([
                'branch' => 'feature/test',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->setTraits([])
            ->transform();
        $domain = $transformer->getOutput();
        $this->assertStringNotContainsString('/', $domain);
        $this->assertStringStartsWith('branch-feature-test-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        // Branch domain consistency
        $transformer
            ->setInput([
                'branch' => 'feature/test',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->setTraits([])
            ->transform();
        $domain2 = $transformer->getOutput();
        $this->assertEquals($domain, $domain2);

        // Different resources should produce different domains
        $transformer
            ->setInput([
                'branch' => 'feature/test',
                'resourceId' => 'site789',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->setTraits([])
            ->transform();
        $domain2 = $transformer->getOutput();
        $this->assertNotEquals($domain, $domain2);

        // Different projects should produce different domains
        $transformer
            ->setInput([
                'branch' => 'feature/test',
                'resourceId' => 'site123',
                'projectId' => 'proj789',
                'sitesDomain' => 'appwrite.network'
            ])
            ->setTraits([])
            ->transform();
        $domain2 = $transformer->getOutput();
        $this->assertNotEquals($domain, $domain2);

        // Some real-world branch names
        $transformer
            ->setInput([
                'branch' => 'feature/SER-1234',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->setTraits([])
            ->transform();
        $domain = $transformer->getOutput();
        $this->assertStringStartsWith('branch-feature-ser-1234-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $transformer
            ->setInput([
                'branch' => 'bugfix/fix-login',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->setTraits([])
            ->transform();
        $domain = $transformer->getOutput();
        $this->assertStringStartsWith('branch-bugfix-fix-login-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $transformer
            ->setInput([
                'branch' => 'hotfix/v1.2.3',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->setTraits([])
            ->transform();
        $domain = $transformer->getOutput();
        $this->assertStringStartsWith('branch-hotfix-v1-2-3-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $transformer
            ->setInput([
                'branch' => 'release/2024.01',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->setTraits([])
            ->transform();
        $domain = $transformer->getOutput();
        $this->assertStringStartsWith('branch-release-2024-01-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $transformer
            ->setInput([
                'branch' => 'user/john/experiment',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->setTraits([])
            ->transform();
        $domain = $transformer->getOutput();
        $this->assertStringStartsWith('branch-user-john-experiment-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);

        $transformer
            ->setInput([
                'branch' => 'dependabot/npm_and_yarn/lodash-4.17.21',
                'resourceId' => 'site123',
                'projectId' => 'proj456',
                'sitesDomain' => 'appwrite.network'
            ])
            ->setTraits([])
            ->transform();
        $domain = $transformer->getOutput();
        $this->assertStringStartsWith('branch-dependabot-npm-and-yarn-lodash-4-17-21-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);
    }
}
