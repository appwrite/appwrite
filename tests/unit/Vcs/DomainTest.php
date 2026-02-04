<?php

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\Domain;
use PHPUnit\Framework\TestCase;

class DomainTest extends TestCase
{
    /**
     * Test sanitizing branch names with various invalid characters
     */
    public function testSanitizeBranchNameWithSlash(): void
    {
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature/test'));
        $this->assertEquals('user-john-fix', Domain::sanitizeBranchName('user/john/fix'));
        $this->assertEquals('abc-test-235', Domain::sanitizeBranchName('abc/test-235'));
    }

    public function testSanitizeBranchNameWithUnderscore(): void
    {
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature_test'));
        $this->assertEquals('my-branch-name', Domain::sanitizeBranchName('my_branch_name'));
    }

    public function testSanitizeBranchNameWithMultipleInvalidChars(): void
    {
        // Multiple consecutive invalid characters should become a single hyphen
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature//test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature__test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature/_test'));
    }

    public function testSanitizeBranchNameWithSpecialChars(): void
    {
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature@test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature#test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature$test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature%test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature&test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature*test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature+test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature=test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature!test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature~test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature`test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature^test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature:test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature;test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature,test'));
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature.test'));
    }

    public function testSanitizeBranchNameWithSpaces(): void
    {
        $this->assertEquals('feature-test', Domain::sanitizeBranchName('feature test'));
        $this->assertEquals('my-branch-name', Domain::sanitizeBranchName('my branch name'));
    }

    public function testSanitizeBranchNameTrimsHyphens(): void
    {
        // Leading and trailing invalid chars should be removed
        $this->assertEquals('feature', Domain::sanitizeBranchName('/feature'));
        $this->assertEquals('feature', Domain::sanitizeBranchName('feature/'));
        $this->assertEquals('feature', Domain::sanitizeBranchName('/feature/'));
        $this->assertEquals('feature', Domain::sanitizeBranchName('//feature//'));
    }

    public function testSanitizeBranchNamePreservesValidChars(): void
    {
        // Valid branch names should remain unchanged
        $this->assertEquals('main', Domain::sanitizeBranchName('main'));
        $this->assertEquals('develop', Domain::sanitizeBranchName('develop'));
        $this->assertEquals('feature-123', Domain::sanitizeBranchName('feature-123'));
        $this->assertEquals('v1-2-3', Domain::sanitizeBranchName('v1-2-3'));
        $this->assertEquals('UPPERCASE', Domain::sanitizeBranchName('UPPERCASE'));
        $this->assertEquals('MixedCase123', Domain::sanitizeBranchName('MixedCase123'));
    }

    public function testSanitizeBranchNameWithEmptyString(): void
    {
        $this->assertEquals('', Domain::sanitizeBranchName(''));
    }

    public function testSanitizeBranchNameWithOnlyInvalidChars(): void
    {
        $this->assertEquals('', Domain::sanitizeBranchName('///'));
        $this->assertEquals('', Domain::sanitizeBranchName('___'));
        $this->assertEquals('', Domain::sanitizeBranchName('@#$'));
    }

    /**
     * Test generating branch prefix for domain names
     */
    public function testGenerateBranchPrefixShortBranch(): void
    {
        // Branch names <= 16 characters should not have hash suffix
        $prefix = Domain::generateBranchPrefix('main');
        $this->assertEquals('main', $prefix);

        $prefix = Domain::generateBranchPrefix('feature-test');
        $this->assertEquals('feature-test', $prefix);

        $prefix = Domain::generateBranchPrefix('exactly16chars12');
        $this->assertEquals('exactly16chars12', $prefix);
    }

    public function testGenerateBranchPrefixLongBranch(): void
    {
        // Branch names > 16 characters should have hash suffix
        $prefix = Domain::generateBranchPrefix('this-is-a-very-long-branch-name');
        // First 16 chars: "this-is-a-very-l" + hash of remaining chars
        $this->assertStringStartsWith('this-is-a-very-l-', $prefix);
        $this->assertEquals(24, strlen($prefix)); // 16 + 1 (hyphen) + 7 (hash)
    }

    public function testGenerateBranchPrefixWithInvalidChars(): void
    {
        // Branch with slash should be sanitized
        $prefix = Domain::generateBranchPrefix('feature/test');
        $this->assertEquals('feature-test', $prefix);

        // Long branch with slash
        $prefix = Domain::generateBranchPrefix('feature/very/long/branch/name');
        $this->assertStringStartsWith('feature-very-lon-', $prefix);
        $this->assertEquals(24, strlen($prefix));
    }

    public function testGenerateBranchPrefixConsistency(): void
    {
        // Same input should produce same output
        $prefix1 = Domain::generateBranchPrefix('feature/my-long-branch-name');
        $prefix2 = Domain::generateBranchPrefix('feature/my-long-branch-name');
        $this->assertEquals($prefix1, $prefix2);
    }

    public function testGenerateBranchPrefixDifferentHashes(): void
    {
        // Different branch names with same first 16 chars should have different hashes
        $prefix1 = Domain::generateBranchPrefix('feature-branch-01234567890');
        $prefix2 = Domain::generateBranchPrefix('feature-branch-0abcdefghij');

        // Both start with sanitized first 16 chars
        $this->assertStringStartsWith('feature-branch-0-', $prefix1);
        $this->assertStringStartsWith('feature-branch-0-', $prefix2);

        // But have different hash suffixes
        $this->assertNotEquals($prefix1, $prefix2);
    }

    /**
     * Test generating full branch domain names
     */
    public function testGenerateBranchDomain(): void
    {
        $domain = Domain::generateBranchDomain('main', 'site123', 'proj456', 'appwrite.network');
        $this->assertStringStartsWith('branch-main-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);
    }

    public function testGenerateBranchDomainWithSlash(): void
    {
        $domain = Domain::generateBranchDomain('feature/test', 'site123', 'proj456', 'appwrite.network');
        // Should NOT contain slash
        $this->assertStringNotContainsString('/', $domain);
        $this->assertStringStartsWith('branch-feature-test-', $domain);
        $this->assertStringEndsWith('.appwrite.network', $domain);
    }

    public function testGenerateBranchDomainConsistency(): void
    {
        // Same inputs should produce same domain
        $domain1 = Domain::generateBranchDomain('feature/test', 'site123', 'proj456', 'appwrite.network');
        $domain2 = Domain::generateBranchDomain('feature/test', 'site123', 'proj456', 'appwrite.network');
        $this->assertEquals($domain1, $domain2);
    }

    public function testGenerateBranchDomainDifferentResources(): void
    {
        // Different resources should produce different domains
        $domain1 = Domain::generateBranchDomain('main', 'site123', 'proj456', 'appwrite.network');
        $domain2 = Domain::generateBranchDomain('main', 'site789', 'proj456', 'appwrite.network');
        $this->assertNotEquals($domain1, $domain2);
    }

    public function testGenerateBranchDomainDifferentProjects(): void
    {
        // Different projects should produce different domains
        $domain1 = Domain::generateBranchDomain('main', 'site123', 'proj456', 'appwrite.network');
        $domain2 = Domain::generateBranchDomain('main', 'site123', 'proj789', 'appwrite.network');
        $this->assertNotEquals($domain1, $domain2);
    }

    /**
     * Test real-world branch name scenarios
     */
    public function testRealWorldBranchNames(): void
    {
        // Common Git branch naming conventions
        $this->assertEquals('feature-SER-1234', Domain::sanitizeBranchName('feature/SER-1234'));
        $this->assertEquals('bugfix-fix-login', Domain::sanitizeBranchName('bugfix/fix-login'));
        $this->assertEquals('hotfix-v1-2-3', Domain::sanitizeBranchName('hotfix/v1.2.3'));
        $this->assertEquals('release-2024-01', Domain::sanitizeBranchName('release/2024.01'));
        $this->assertEquals('user-john-experiment', Domain::sanitizeBranchName('user/john/experiment'));
        $this->assertEquals('dependabot-npm-and-yarn-lodash-4-17-21', Domain::sanitizeBranchName('dependabot/npm_and_yarn/lodash-4.17.21'));
    }
}
