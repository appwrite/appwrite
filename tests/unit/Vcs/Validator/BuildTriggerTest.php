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

    public function testQuestionMarkWildcard(): void
    {
        $validator = new BuildTrigger(['v?.?']);
        $this->assertTrue($validator->isValid('v1.0'));
        $this->assertTrue($validator->isValid('v2.5'));
        $this->assertFalse($validator->isValid('v10.0')); // ? matches exactly one char, not two
        $this->assertFalse($validator->isValid('v1/0'));  // ? does not cross /
    }

    public function testQuestionMarkDoesNotCrossSlash(): void
    {
        $validator = new BuildTrigger(['feature/?']);
        $this->assertTrue($validator->isValid('feature/a'));
        $this->assertTrue($validator->isValid('feature/z'));
        $this->assertFalse($validator->isValid('feature/ab'));   // ? matches only one char
        $this->assertFalse($validator->isValid('feature/a/b')); // ? does not cross /
        $this->assertFalse($validator->isValid('feature/'));
    }

    public function testQuestionMarkMixedWithStar(): void
    {
        $validator = new BuildTrigger(['fix-?.*']);
        $this->assertTrue($validator->isValid('fix-1.php'));
        $this->assertTrue($validator->isValid('fix-a.js'));
        $this->assertFalse($validator->isValid('fix-12.php')); // ? matches only one char
        $this->assertFalse($validator->isValid('fix-.php'));   // ? requires exactly one char
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
        // feature/wip matches wildcard inclusion feature/* but specific exclusion !feature/wip overrides it
        $validator = new BuildTrigger(['main', 'develop', 'feature/*', '!feature/wip']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('develop'));
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertFalse($validator->isValid('feature/wip'));   // specific exclusion overrides wildcard inclusion
        $this->assertFalse($validator->isValid('hotfix/urgent')); // no inclusion match
    }

    public function testSingleInclusionWithMultipleExclusions(): void
    {
        // specific exclusions !feature/wip and !feature/experimental override wildcard inclusion feature/**
        $validator = new BuildTrigger(['feature/**', '!feature/wip', '!feature/experimental']);
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertTrue($validator->isValid('feature/a/b'));
        $this->assertFalse($validator->isValid('feature/wip'));          // specific exclusion wins
        $this->assertFalse($validator->isValid('feature/experimental')); // specific exclusion wins
        $this->assertFalse($validator->isValid('main'));                  // no inclusion match
    }

    public function testMultipleInclusionsWithMultipleExclusions(): void
    {
        // specific exclusions override the wildcard inclusion; specific inclusion 'main' is unaffected
        $validator = new BuildTrigger(['main', 'feature/**', '!feature/wip', '!feature/experimental']);
        $this->assertTrue($validator->isValid('main'));          // specific inclusion wins regardless
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertTrue($validator->isValid('feature/a/b'));
        $this->assertFalse($validator->isValid('feature/wip'));          // specific exclusion wins
        $this->assertFalse($validator->isValid('feature/experimental')); // specific exclusion wins
        $this->assertFalse($validator->isValid('develop'));              // no inclusion match
    }

    public function testWildcardExclusionOverridesWildcardInclusion(): void
    {
        // src/** is a broad inclusion; !src/generated/** carves out the generated subtree
        $validator = new BuildTrigger(['src/**', '!src/generated/**']);
        $this->assertTrue($validator->isValid('src/components/Button.php'));
        $this->assertTrue($validator->isValid('src/utils/helper.js'));
        $this->assertFalse($validator->isValid('src/generated/Foo.php'));      // wildcard exclusion wins
        $this->assertFalse($validator->isValid('src/generated/bar/Baz.php')); // wildcard exclusion wins
        $this->assertFalse($validator->isValid('lib/other.php'));              // no inclusion match
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

    // -------------------------------------------------------------------------
    // Standalone wildcards
    // -------------------------------------------------------------------------

    public function testStarAloneMatchesSingleSegmentOnly(): void
    {
        // * → ^[^/]*$ — matches any single segment; the [^/]* guard prevents crossing /
        $validator = new BuildTrigger(['*']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('develop'));
        $this->assertFalse($validator->isValid('feature/foo')); // * cannot cross /
        $this->assertFalse($validator->isValid('a/b/c'));
    }

    public function testDoubleStarAloneMatchesEverything(): void
    {
        // ** alone → ^.*$ — matches any string including paths with separators
        $validator = new BuildTrigger(['**']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertTrue($validator->isValid('src/a/b/c/d/file.php'));
    }

    // -------------------------------------------------------------------------
    // Extension patterns — * scope vs. ** scope
    // -------------------------------------------------------------------------

    public function testStarDotExtMatchesRootLevelOnly(): void
    {
        // *.php → ^[^/]*\.php$ — root-level only; * cannot cross /
        $validator = new BuildTrigger(['*.php']);
        $this->assertTrue($validator->isValid('Foo.php'));
        $this->assertTrue($validator->isValid('index.php'));
        $this->assertFalse($validator->isValid('src/Foo.php')); // * does not cross /
        $this->assertFalse($validator->isValid('a/b/Foo.php'));
        $this->assertFalse($validator->isValid('Foo.js'));
    }

    public function testDoubleStarSlashExtMatchesAnyDepth(): void
    {
        // **/*.php → ^(?:.+/)?[^/]*\.php$ — (?:.+/)? is optional so root also matches
        $validator = new BuildTrigger(['**/*.php']);
        $this->assertTrue($validator->isValid('Foo.php'));                // root — zero leading dirs
        $this->assertTrue($validator->isValid('src/Foo.php'));
        $this->assertTrue($validator->isValid('src/components/Foo.php'));
        $this->assertTrue($validator->isValid('a/b/c/d/Foo.php'));        // four levels deep
        $this->assertFalse($validator->isValid('Foo.js'));
        $this->assertFalse($validator->isValid('src/Foo.js'));
    }

    public function testDirPrefixDoubleStarExtPattern(): void
    {
        // src/**/*.php → ^src/(?:.+/)?[^/]*\.php$ — scoped to src/ at any depth
        $validator = new BuildTrigger(['src/**/*.php']);
        $this->assertTrue($validator->isValid('src/Foo.php'));
        $this->assertTrue($validator->isValid('src/components/Foo.php'));
        $this->assertTrue($validator->isValid('src/a/b/c/Foo.php'));
        $this->assertFalse($validator->isValid('Foo.php'));     // outside src/
        $this->assertFalse($validator->isValid('lib/Foo.php'));
        $this->assertFalse($validator->isValid('src/Foo.js'));
    }

    // -------------------------------------------------------------------------
    // Dots as literal characters
    // -------------------------------------------------------------------------

    public function testDotsInPatternAreLiteral(): void
    {
        // preg_quote escapes . to \. — dots are never regex wildcards
        $validator = new BuildTrigger(['release-1.0.0']);
        $this->assertTrue($validator->isValid('release-1.0.0'));
        $this->assertFalse($validator->isValid('release-1X0Y0'));        // X/Y must not satisfy a literal dot
        $this->assertFalse($validator->isValid('release-1.0.0-hotfix')); // extra suffix rejected by $ anchor
    }

    public function testVersionWildcardBranchPattern(): void
    {
        // v*.*.* → ^v[^/]*\.[^/]*\.[^/]*$ — dots literal; [^/]* is greedy and can consume dots,
        // so v1.2.3.4 also matches (first [^/]* eats "1.2") — documents current behavior
        $validator = new BuildTrigger(['v*.*.*']);
        $this->assertTrue($validator->isValid('v1.2.3'));
        $this->assertTrue($validator->isValid('v10.20.30'));
        $this->assertTrue($validator->isValid('v1.2.3.4'));  // greedy match — first [^/]* eats "1.2"
        $this->assertFalse($validator->isValid('v1.2'));     // only two dot-segments; third \.[^/]* fails
        $this->assertFalse($validator->isValid('1.2.3'));    // missing leading v
        $this->assertFalse($validator->isValid('v1/2/3'));   // [^/]* cannot cross /
    }

    public function testDottedFilenamePattern(): void
    {
        // *.test.js → ^[^/]*\.test\.js$ — both dots literal; * stays in root segment
        $validator = new BuildTrigger(['*.test.js']);
        $this->assertTrue($validator->isValid('Button.test.js'));
        $this->assertTrue($validator->isValid('App.test.js'));
        $this->assertFalse($validator->isValid('ButtonXtestYjs'));      // dots must be literal
        $this->assertFalse($validator->isValid('src/Button.test.js'));  // * does not cross /
        $this->assertFalse($validator->isValid('Button.test.ts'));
    }

    // -------------------------------------------------------------------------
    // Prefix wildcard
    // -------------------------------------------------------------------------

    public function testPrefixWildcardBranchPattern(): void
    {
        // main* → ^main[^/]*$ — suffix wildcard; [^/]* cannot cross /
        $validator = new BuildTrigger(['main*']);
        $this->assertTrue($validator->isValid('main'));          // zero trailing chars
        $this->assertTrue($validator->isValid('main-extra'));
        $this->assertTrue($validator->isValid('mainline'));
        $this->assertFalse($validator->isValid('main/branch')); // [^/]* cannot cross /
        $this->assertFalse($validator->isValid('develop'));
    }

    // -------------------------------------------------------------------------
    // Deep nesting
    // -------------------------------------------------------------------------

    public function testDoubleWildcardInMiddleDeepNesting(): void
    {
        // a/**/b → ^a/(?:.+/)?b$ — .+ inside (?:.+/)? matches any chars including /,
        // so it handles arbitrarily many intermediate directories
        $validator = new BuildTrigger(['a/**/b']);
        $this->assertTrue($validator->isValid('a/x/y/z/b'));        // three intermediate dirs
        $this->assertTrue($validator->isValid('a/p/q/r/s/b'));      // four intermediate dirs
        $this->assertTrue($validator->isValid('a/1/2/3/4/5/b'));    // five intermediate dirs
        $this->assertFalse($validator->isValid('a/x/y/z/b/extra')); // trailing segment rejected
        $this->assertFalse($validator->isValid('prefix/a/x/b'));    // leading segment rejected
    }

    public function testDoubleWildcardAtStartDeepNesting(): void
    {
        // **/README.md → ^(?:.+/)?README\.md$ — matches at any depth; $ anchor prevents suffixes
        $validator = new BuildTrigger(['**/README.md']);
        $this->assertTrue($validator->isValid('README.md'));              // zero leading dirs
        $this->assertTrue($validator->isValid('docs/README.md'));
        $this->assertTrue($validator->isValid('a/b/c/d/README.md'));      // four levels deep
        $this->assertTrue($validator->isValid('x/y/z/w/v/README.md'));    // five levels deep
        $this->assertFalse($validator->isValid('a/b/c/README.md.bak'));   // $ anchor — no suffix
        $this->assertFalse($validator->isValid('a/b/c/README.md/extra')); // trailing segment rejected
    }

    // -------------------------------------------------------------------------
    // Real-world path patterns
    // -------------------------------------------------------------------------

    public function testGeneratedFilesAnywhereExclusion(): void
    {
        // !**/generated/** → ^(?:.+/)?generated/.*$ — excludes generated/ at any depth
        $validator = new BuildTrigger(['!**/generated/**']);
        $this->assertFalse($validator->isValid('generated/Foo.php'));           // root generated/
        $this->assertFalse($validator->isValid('src/generated/Foo.php'));
        $this->assertFalse($validator->isValid('src/api/generated/Bar.php'));   // deep generated/
        $this->assertFalse($validator->isValid('generated/sub/deep/File.php')); // deep inside root generated/
        $this->assertTrue($validator->isValid('src/components/Button.php'));    // not under generated/
        $this->assertTrue($validator->isValid('main'));
    }

    public function testMultipleExtensionInclusions(): void
    {
        // OR semantics: any PHP or JS file triggers; other extensions do not
        $validator = new BuildTrigger(['**/*.php', '**/*.js']);
        $this->assertTrue($validator->isValid('index.php'));
        $this->assertTrue($validator->isValid('src/App.php'));
        $this->assertTrue($validator->isValid('index.js'));
        $this->assertTrue($validator->isValid('src/components/App.js'));
        $this->assertFalse($validator->isValid('styles.css'));
        $this->assertFalse($validator->isValid('src/styles.css'));
        $this->assertFalse($validator->isValid('README.md'));
    }

    // -------------------------------------------------------------------------
    // Named-prefix single-level branch
    // -------------------------------------------------------------------------

    public function testReleaseBranchPattern(): void
    {
        // release/* → ^release/[^/]*$ — one level only; * stops at the next /
        $validator = new BuildTrigger(['release/*']);
        $this->assertTrue($validator->isValid('release/1.0'));
        $this->assertTrue($validator->isValid('release/hotfix'));
        $this->assertTrue($validator->isValid('release/2024-01-15'));
        $this->assertFalse($validator->isValid('release/1.0/patch')); // * stops at /
        $this->assertFalse($validator->isValid('release'));             // missing the /* segment
        $this->assertFalse($validator->isValid('main'));
    }

    // -------------------------------------------------------------------------
    // Case sensitivity
    // -------------------------------------------------------------------------

    public function testPatternMatchingIsCaseSensitive(): void
    {
        // preg_match is used without the i flag — matching is always case-sensitive
        $branchValidator = new BuildTrigger(['main']);
        $this->assertTrue($branchValidator->isValid('main'));
        $this->assertFalse($branchValidator->isValid('Main'));
        $this->assertFalse($branchValidator->isValid('MAIN'));

        $wildcardValidator = new BuildTrigger(['feature/*']);
        $this->assertTrue($wildcardValidator->isValid('feature/foo'));
        $this->assertFalse($wildcardValidator->isValid('Feature/foo'));
        $this->assertFalse($wildcardValidator->isValid('FEATURE/foo'));
    }
}
