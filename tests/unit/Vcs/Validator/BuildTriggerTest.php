<?php

namespace Tests\Unit\Vcs\Validator;

use Appwrite\Vcs\Validator\BuildTrigger;
use PHPUnit\Framework\TestCase;

class BuildTriggerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Empty patterns
    // -------------------------------------------------------------------------

    public function testEmptyPatternsAlwaysPass(): void
    {
        $validator = new BuildTrigger([]);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('feature/anything'));
        $this->assertTrue($validator->isValid('src/deep/nested/file.php'));
    }

    // -------------------------------------------------------------------------
    // Pure inclusion — OR semantics (any one match is enough)
    // -------------------------------------------------------------------------

    public function testSingleExactInclusion(): void
    {
        $validator = new BuildTrigger(['main']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertFalse($validator->isValid('develop'));
        $this->assertFalse($validator->isValid('main-extra'));
    }

    public function testMultipleExactInclusionsOr(): void
    {
        $validator = new BuildTrigger(['main', 'develop', 'staging']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('develop'));
        $this->assertTrue($validator->isValid('staging'));
        $this->assertFalse($validator->isValid('feature/foo'));
        $this->assertFalse($validator->isValid('production'));
    }

    public function testSingleWildcardInclusion(): void
    {
        $validator = new BuildTrigger(['feature/*']);
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertTrue($validator->isValid('feature/bar'));
        $this->assertFalse($validator->isValid('feature/foo/bar')); // * does not cross /
        $this->assertFalse($validator->isValid('main'));
    }

    public function testWildcardWithDash(): void
    {
        $validator = new BuildTrigger(['feature/test-*']);
        $this->assertTrue($validator->isValid('feature/test-1'));
        $this->assertTrue($validator->isValid('feature/test-abc'));
        $this->assertFalse($validator->isValid('feature/other'));
        $this->assertFalse($validator->isValid('feature/test'));
    }

    public function testDoubleWildcardAtEnd(): void
    {
        $validator = new BuildTrigger(['src/**']);
        $this->assertTrue($validator->isValid('src/foo.js'));
        $this->assertTrue($validator->isValid('src/a/b/c.js'));
        $this->assertTrue($validator->isValid('src/deep/nested/file.php'));
        $this->assertFalse($validator->isValid('lib/foo.js'));
    }

    public function testDoubleWildcardInMiddle(): void
    {
        $validator = new BuildTrigger(['a/**/b']);
        $this->assertTrue($validator->isValid('a/b'));      // zero intermediate dirs
        $this->assertTrue($validator->isValid('a/x/b'));    // one
        $this->assertTrue($validator->isValid('a/x/y/b')); // two
        $this->assertFalse($validator->isValid('a/b/c'));
        $this->assertFalse($validator->isValid('x/a/b'));
    }

    public function testDoubleWildcardAtStart(): void
    {
        $validator = new BuildTrigger(['**/foo']);
        $this->assertTrue($validator->isValid('foo'));       // zero leading dirs
        $this->assertTrue($validator->isValid('a/foo'));     // one
        $this->assertTrue($validator->isValid('a/b/foo'));   // two
        $this->assertFalse($validator->isValid('foobar'));
        $this->assertFalse($validator->isValid('a/foobar'));
    }

    public function testMixedExactAndWildcardInclusions(): void
    {
        $validator = new BuildTrigger(['main', 'feature/*']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertFalse($validator->isValid('develop'));
        $this->assertFalse($validator->isValid('feature/foo/bar'));
    }

    // -------------------------------------------------------------------------
    // Pure exclusion — AND semantics (must not match any exclusion)
    // -------------------------------------------------------------------------

    public function testSingleExactExclusion(): void
    {
        $validator = new BuildTrigger(['!main']);
        $this->assertFalse($validator->isValid('main'));
        $this->assertTrue($validator->isValid('develop'));
        $this->assertTrue($validator->isValid('feature/foo'));
    }

    public function testMultipleExactExclusionsAnd(): void
    {
        $validator = new BuildTrigger(['!main', '!develop']);
        $this->assertFalse($validator->isValid('main'));
        $this->assertFalse($validator->isValid('develop'));
        $this->assertTrue($validator->isValid('staging'));    // neither excluded
        $this->assertTrue($validator->isValid('feature/foo'));
    }

    public function testWildcardExclusion(): void
    {
        $validator = new BuildTrigger(['!feature/*']);
        $this->assertFalse($validator->isValid('feature/foo'));
        $this->assertFalse($validator->isValid('feature/bar'));
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('hotfix/urgent')); // not matched by feature/*
    }

    public function testDoubleWildcardExclusion(): void
    {
        $validator = new BuildTrigger(['!src/**']);
        $this->assertFalse($validator->isValid('src/foo.js'));
        $this->assertFalse($validator->isValid('src/a/b/c.js'));
        $this->assertTrue($validator->isValid('lib/foo.js'));
        $this->assertTrue($validator->isValid('main'));
    }

    // -------------------------------------------------------------------------
    // Mixed inclusion + exclusion
    // -------------------------------------------------------------------------

    public function testInclusionTakesPrecedenceWhenBothMatch(): void
    {
        // feature/abc matches both '!feature/*' (exclusion) and 'feature/abc' (inclusion)
        // Inclusion is checked first, so it wins
        $validator = new BuildTrigger(['!feature/*', 'feature/abc']);
        $this->assertTrue($validator->isValid('feature/abc'));  // inclusion wins
        $this->assertFalse($validator->isValid('feature/xyz')); // only exclusion matches
        $this->assertFalse($validator->isValid('main'));        // no inclusion matches
    }

    public function testInclusionWithNoMatchFails(): void
    {
        // Inclusions exist but none match — exclusion is irrelevant
        $validator = new BuildTrigger(['main', '!develop']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertFalse($validator->isValid('develop'));  // excluded even if inclusion didn't match
        $this->assertFalse($validator->isValid('staging')); // no inclusion match
    }

    public function testExclusionBlocksWhenInclusionDoesNotMatch(): void
    {
        $validator = new BuildTrigger(['feature/*', '!hotfix/*']);
        $this->assertTrue($validator->isValid('feature/foo'));   // matches inclusion
        $this->assertFalse($validator->isValid('hotfix/urgent')); // no inclusion match, also excluded
        $this->assertFalse($validator->isValid('main'));          // no inclusion match
    }

    public function testMultipleInclusionsWithSingleExclusion(): void
    {
        // feature/wip matches the inclusion feature/* → true (exclusion !feature/wip is never reached)
        $validator = new BuildTrigger(['main', 'develop', 'feature/*', '!feature/wip']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('develop'));
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertTrue($validator->isValid('feature/wip'));    // inclusion wins
        $this->assertFalse($validator->isValid('hotfix/urgent')); // no inclusion match
    }

    public function testSingleInclusionWithMultipleExclusions(): void
    {
        // feature/wip and feature/experimental both match the inclusion feature/** → true
        $validator = new BuildTrigger(['feature/**', '!feature/wip', '!feature/experimental']);
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertTrue($validator->isValid('feature/a/b'));
        $this->assertTrue($validator->isValid('feature/wip'));          // inclusion wins
        $this->assertTrue($validator->isValid('feature/experimental')); // inclusion wins
        $this->assertFalse($validator->isValid('main'));                 // no inclusion match
    }

    public function testMultipleInclusionsWithMultipleExclusions(): void
    {
        // feature/wip and feature/experimental both match the inclusion feature/** → true
        $validator = new BuildTrigger(['main', 'feature/**', '!feature/wip', '!feature/experimental']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertTrue($validator->isValid('feature/a/b'));
        $this->assertTrue($validator->isValid('feature/wip'));          // inclusion wins
        $this->assertTrue($validator->isValid('feature/experimental')); // inclusion wins
        $this->assertFalse($validator->isValid('develop'));             // no inclusion match
    }

    public function testSpecificInclusionOverridesWildcardExclusion(): void
    {
        // Narrow allowlist with a carve-out: exclude all of feature/* except feature/hotfix/critical
        $validator = new BuildTrigger(['feature/hotfix/critical', '!feature/**']);
        $this->assertTrue($validator->isValid('feature/hotfix/critical')); // inclusion wins
        $this->assertFalse($validator->isValid('feature/foo'));             // excluded
        $this->assertFalse($validator->isValid('feature/hotfix/other'));    // excluded
        $this->assertFalse($validator->isValid('main'));                    // no inclusion match
    }

    public function testOnlyExclusionsDefaultToTrueUnlessExcluded(): void
    {
        $validator = new BuildTrigger(['!main', '!develop']);
        $this->assertFalse($validator->isValid('main'));
        $this->assertFalse($validator->isValid('develop'));
        $this->assertTrue($validator->isValid('staging'));    // passes — no inclusions required
        $this->assertTrue($validator->isValid('feature/foo'));
    }
}
