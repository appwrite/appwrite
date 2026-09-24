<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class GlobstarTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Empty patterns
    // -------------------------------------------------------------------------

    public function testEmptyPatternsAlwaysPass(): void
    {
        $validator = new Globstar([]);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('feature/anything'));
        $this->assertTrue($validator->isValid('src/deep/nested/file.php'));
    }

    // -------------------------------------------------------------------------
    // Pure inclusion — OR semantics (any one match is enough)
    // -------------------------------------------------------------------------

    public function testSingleExactInclusion(): void
    {
        $validator = new Globstar(['main']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertFalse($validator->isValid('develop'));
        $this->assertFalse($validator->isValid('main-extra'));
    }

    public function testMultipleExactInclusionsOr(): void
    {
        $validator = new Globstar(['main', 'develop', 'staging']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('develop'));
        $this->assertTrue($validator->isValid('staging'));
        $this->assertFalse($validator->isValid('feature/foo'));
        $this->assertFalse($validator->isValid('production'));
    }

    public function testSingleWildcardInclusion(): void
    {
        $validator = new Globstar(['feature/*']);
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertTrue($validator->isValid('feature/bar'));
        $this->assertFalse($validator->isValid('feature/foo/bar')); // * does not cross /
        $this->assertFalse($validator->isValid('main'));
    }

    public function testSingleWildcardWithDirectoryPrefix(): void
    {
        // baz/*.txt — * matches within a single directory segment
        $this->assertTrue(new Globstar(['baz/*.txt'])->isValid('baz/file.txt'));
        $this->assertFalse(new Globstar(['baz/*.txt'])->isValid('a/baz/file.txt')); // prefix must match literally
        $this->assertFalse(new Globstar(['baz/*.txt'])->isValid('baz/file.log'));
    }

    public function testWildcardWithDash(): void
    {
        $validator = new Globstar(['feature/test-*']);
        $this->assertTrue($validator->isValid('feature/test-1'));
        $this->assertTrue($validator->isValid('feature/test-abc'));
        $this->assertFalse($validator->isValid('feature/other'));
        $this->assertFalse($validator->isValid('feature/test'));
    }

    public function testQuestionMarkWildcard(): void
    {
        $validator = new Globstar(['v?.?']);
        $this->assertTrue($validator->isValid('v1.0'));
        $this->assertTrue($validator->isValid('v2.5'));
        $this->assertFalse($validator->isValid('v10.0')); // ? matches exactly one char, not two
        $this->assertFalse($validator->isValid('v1/0'));  // ? does not cross /
    }

    public function testQuestionMarkDoesNotCrossSlash(): void
    {
        $validator = new Globstar(['feature/?']);
        $this->assertTrue($validator->isValid('feature/a'));
        $this->assertTrue($validator->isValid('feature/z'));
        $this->assertFalse($validator->isValid('feature/ab'));   // ? matches only one char
        $this->assertFalse($validator->isValid('feature/a/b')); // ? does not cross /
        $this->assertFalse($validator->isValid('feature/'));
    }

    public function testQuestionMarkMixedWithStar(): void
    {
        $validator = new Globstar(['fix-?.*']);
        $this->assertTrue($validator->isValid('fix-1.php'));
        $this->assertTrue($validator->isValid('fix-a.js'));
        $this->assertFalse($validator->isValid('fix-12.php')); // ? matches only one char
        $this->assertFalse($validator->isValid('fix-.php'));   // ? requires exactly one char
    }

    public function testQuestionMarkSuffix(): void
    {
        // qux? — question mark matches exactly one character as suffix
        $this->assertTrue(new Globstar(['qux?'])->isValid('qux1'));
        $this->assertTrue(new Globstar(['qux?'])->isValid('quxa'));
        $this->assertFalse(new Globstar(['qux?'])->isValid('qux'));   // requires exactly one char
        $this->assertFalse(new Globstar(['qux?'])->isValid('qux12')); // does not match two chars
    }

    public function testDoubleWildcardAtEnd(): void
    {
        $validator = new Globstar(['src/**']);
        $this->assertTrue($validator->isValid('src/foo.js'));
        $this->assertTrue($validator->isValid('src/a/b/c.js'));
        $this->assertTrue($validator->isValid('src/deep/nested/file.php'));
        $this->assertFalse($validator->isValid('lib/foo.js'));
    }

    public function testDoubleWildcardInMiddle(): void
    {
        $validator = new Globstar(['a/**/b']);
        $this->assertTrue($validator->isValid('a/b'));      // zero intermediate dirs
        $this->assertTrue($validator->isValid('a/x/b'));    // one
        $this->assertTrue($validator->isValid('a/x/y/b')); // two
        $this->assertFalse($validator->isValid('a/b/c'));
        $this->assertFalse($validator->isValid('x/a/b'));
    }

    public function testDoubleWildcardAtStart(): void
    {
        $validator = new Globstar(['**/foo']);
        $this->assertTrue($validator->isValid('foo'));       // zero leading dirs
        $this->assertTrue($validator->isValid('a/foo'));     // one
        $this->assertTrue($validator->isValid('a/b/foo'));   // two
        $this->assertFalse($validator->isValid('foobar'));
        $this->assertFalse($validator->isValid('a/foobar'));
    }

    public function testMixedExactAndWildcardInclusions(): void
    {
        $validator = new Globstar(['main', 'feature/*']);
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
        $validator = new Globstar(['!main']);
        $this->assertFalse($validator->isValid('main'));
        $this->assertTrue($validator->isValid('develop'));
        $this->assertTrue($validator->isValid('feature/foo'));
    }

    public function testMultipleExactExclusionsAnd(): void
    {
        $validator = new Globstar(['!main', '!develop']);
        $this->assertFalse($validator->isValid('main'));
        $this->assertFalse($validator->isValid('develop'));
        $this->assertTrue($validator->isValid('staging'));
        $this->assertTrue($validator->isValid('feature/foo'));
    }

    public function testWildcardExclusion(): void
    {
        $validator = new Globstar(['!feature/*']);
        $this->assertFalse($validator->isValid('feature/foo'));
        $this->assertFalse($validator->isValid('feature/bar'));
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('hotfix/urgent'));
    }

    public function testDoubleWildcardExclusion(): void
    {
        $validator = new Globstar(['!src/**']);
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
        $validator = new Globstar(['!feature/*', 'feature/abc']);
        $this->assertTrue($validator->isValid('feature/abc'));  // inclusion wins
        $this->assertFalse($validator->isValid('feature/xyz')); // only exclusion matches
        $this->assertFalse($validator->isValid('main'));        // no inclusion matches
    }

    public function testInclusionWithNoMatchFails(): void
    {
        $validator = new Globstar(['main', '!develop']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertFalse($validator->isValid('develop'));  // excluded even if inclusion didn't match
        $this->assertFalse($validator->isValid('staging')); // no inclusion match
    }

    public function testExclusionBlocksWhenInclusionDoesNotMatch(): void
    {
        $validator = new Globstar(['feature/*', '!hotfix/*']);
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertFalse($validator->isValid('hotfix/urgent')); // no inclusion match, also excluded
        $this->assertFalse($validator->isValid('main'));          // no inclusion match
    }

    public function testMultipleInclusionsWithSingleExclusion(): void
    {
        $validator = new Globstar(['main', 'develop', 'feature/*', '!feature/wip']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('develop'));
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertFalse($validator->isValid('feature/wip'));   // specific exclusion overrides wildcard inclusion
        $this->assertFalse($validator->isValid('hotfix/urgent')); // no inclusion match
    }

    public function testSingleInclusionWithMultipleExclusions(): void
    {
        $validator = new Globstar(['feature/**', '!feature/wip', '!feature/experimental']);
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertTrue($validator->isValid('feature/a/b'));
        $this->assertFalse($validator->isValid('feature/wip'));
        $this->assertFalse($validator->isValid('feature/experimental'));
        $this->assertFalse($validator->isValid('main'));
    }

    public function testMultipleInclusionsWithMultipleExclusions(): void
    {
        $validator = new Globstar(['main', 'feature/**', '!feature/wip', '!feature/experimental']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertTrue($validator->isValid('feature/a/b'));
        $this->assertFalse($validator->isValid('feature/wip'));
        $this->assertFalse($validator->isValid('feature/experimental'));
        $this->assertFalse($validator->isValid('develop'));
    }

    public function testWildcardExclusionOverridesWildcardInclusion(): void
    {
        $validator = new Globstar(['src/**', '!src/generated/**']);
        $this->assertTrue($validator->isValid('src/components/Button.php'));
        $this->assertTrue($validator->isValid('src/utils/helper.js'));
        $this->assertFalse($validator->isValid('src/generated/Foo.php'));
        $this->assertFalse($validator->isValid('src/generated/bar/Baz.php'));
        $this->assertFalse($validator->isValid('lib/other.php'));
    }

    public function testSpecificInclusionOverridesWildcardExclusion(): void
    {
        $validator = new Globstar(['feature/hotfix/critical', '!feature/**']);
        $this->assertTrue($validator->isValid('feature/hotfix/critical')); // inclusion wins
        $this->assertFalse($validator->isValid('feature/foo'));
        $this->assertFalse($validator->isValid('feature/hotfix/other'));
        $this->assertFalse($validator->isValid('main'));
    }

    public function testOnlyExclusionsDefaultToTrueUnlessExcluded(): void
    {
        $validator = new Globstar(['!main', '!develop']);
        $this->assertFalse($validator->isValid('main'));
        $this->assertFalse($validator->isValid('develop'));
        $this->assertTrue($validator->isValid('staging'));
        $this->assertTrue($validator->isValid('feature/foo'));
    }

    // -------------------------------------------------------------------------
    // Standalone wildcards
    // -------------------------------------------------------------------------

    public function testStarAloneMatchesSingleSegmentOnly(): void
    {
        $validator = new Globstar(['*']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('develop'));
        $this->assertFalse($validator->isValid('feature/foo')); // * cannot cross /
        $this->assertFalse($validator->isValid('a/b/c'));
    }

    public function testDoubleStarAloneMatchesEverything(): void
    {
        $validator = new Globstar(['**']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertTrue($validator->isValid('src/a/b/c/d/file.php'));
    }

    // -------------------------------------------------------------------------
    // Extension patterns — * scope vs. ** scope
    // -------------------------------------------------------------------------

    public function testStarDotExtMatchesRootLevelOnly(): void
    {
        $validator = new Globstar(['*.php']);
        $this->assertTrue($validator->isValid('Foo.php'));
        $this->assertTrue($validator->isValid('index.php'));
        $this->assertFalse($validator->isValid('src/Foo.php')); // * does not cross /
        $this->assertFalse($validator->isValid('a/b/Foo.php'));
        $this->assertFalse($validator->isValid('Foo.js'));
    }

    public function testDoubleStarSlashExtMatchesAnyDepth(): void
    {
        $validator = new Globstar(['**/*.php']);
        $this->assertTrue($validator->isValid('Foo.php'));
        $this->assertTrue($validator->isValid('src/Foo.php'));
        $this->assertTrue($validator->isValid('src/components/Foo.php'));
        $this->assertTrue($validator->isValid('a/b/c/d/Foo.php'));
        $this->assertFalse($validator->isValid('Foo.js'));
        $this->assertFalse($validator->isValid('src/Foo.js'));
    }

    public function testDirPrefixDoubleStarExtPattern(): void
    {
        $validator = new Globstar(['src/**/*.php']);
        $this->assertTrue($validator->isValid('src/Foo.php'));
        $this->assertTrue($validator->isValid('src/components/Foo.php'));
        $this->assertTrue($validator->isValid('src/a/b/c/Foo.php'));
        $this->assertFalse($validator->isValid('Foo.php'));
        $this->assertFalse($validator->isValid('lib/Foo.php'));
        $this->assertFalse($validator->isValid('src/Foo.js'));
    }

    public function testDoubleWildcardAtStartAlternateFilename(): void
    {
        // **/temp.txt — different filename from existing **/file.txt tests
        $this->assertTrue(new Globstar(['**/temp.txt'])->isValid('temp.txt'));
        $this->assertTrue(new Globstar(['**/temp.txt'])->isValid('a/temp.txt'));
        $this->assertTrue(new Globstar(['**/temp.txt'])->isValid('a/b/temp.txt'));
    }

    public function testDoubleWildcardAtEndNoExtension(): void
    {
        // src/** without extension filter
        $this->assertTrue(new Globstar(['src/**'])->isValid('src/file.txt'));
        $this->assertTrue(new Globstar(['src/**'])->isValid('src/a/file.txt'));
    }

    public function testDoubleWildcardLogExtension(): void
    {
        // src/**/*.log
        $this->assertTrue(new Globstar(['src/**/*.log'])->isValid('src/error.log'));
        $this->assertTrue(new Globstar(['src/**/*.log'])->isValid('src/a/debug.log'));
    }

    public function testDoubleWildcardMiddleWithTwoSegmentTail(): void
    {
        // a/**/b/c — tail is two segments (b/c)
        $this->assertTrue(new Globstar(['a/**/b/c'])->isValid('a/b/c'));      // zero intermediate
        $this->assertTrue(new Globstar(['a/**/b/c'])->isValid('a/x/b/c'));    // one intermediate
        $this->assertTrue(new Globstar(['a/**/b/c'])->isValid('a/x/y/b/c')); // two intermediate
        $this->assertFalse(new Globstar(['a/**/b/c'])->isValid('a/b/d'));     // wrong tail
    }

    public function testDoubleWildcardBothSides(): void
    {
        // **/d/e/** — globstar on both sides of a literal segment pair
        $this->assertTrue(new Globstar(['**/d/e/**'])->isValid('d/e/file.txt'));
        $this->assertTrue(new Globstar(['**/d/e/**'])->isValid('x/d/e/file.txt'));
        $this->assertTrue(new Globstar(['**/d/e/**'])->isValid('x/y/d/e/z/file.txt'));
        $this->assertFalse(new Globstar(['**/d/e/**'])->isValid('d/f/file.txt'));
    }

    public function testDoubleWildcardWithJsExtension(): void
    {
        // src/foo/**/*.js
        $this->assertTrue(new Globstar(['src/foo/**/*.js'])->isValid('src/foo/app/file.js'));
        $this->assertTrue(new Globstar(['src/foo/**/*.js'])->isValid('src/foo/file.js'));
        $this->assertFalse(new Globstar(['src/foo/**/*.js'])->isValid('src/bar/app/file.js'));
    }

    public function testDoubleWildcardDeepNesting(): void
    {
        // Very deep path with ** in the middle
        $this->assertTrue(new Globstar(['deep/**/logs/*.log'])->isValid('deep/level1/level2/level3/level4/level5/level6/level7/logs/app.log'));
    }

    // -------------------------------------------------------------------------
    // Dots as literal characters
    // -------------------------------------------------------------------------

    public function testDotsInPatternAreLiteral(): void
    {
        $validator = new Globstar(['release-1.0.0']);
        $this->assertTrue($validator->isValid('release-1.0.0'));
        $this->assertFalse($validator->isValid('release-1X0Y0'));
        $this->assertFalse($validator->isValid('release-1.0.0-hotfix'));
    }

    public function testVersionWildcardBranchPattern(): void
    {
        $validator = new Globstar(['v*.*.*']);
        $this->assertTrue($validator->isValid('v1.2.3'));
        $this->assertTrue($validator->isValid('v10.20.30'));
        $this->assertTrue($validator->isValid('v1.2.3.4'));
        $this->assertFalse($validator->isValid('v1.2'));
        $this->assertFalse($validator->isValid('1.2.3'));
        $this->assertFalse($validator->isValid('v1/2/3'));
    }

    public function testDottedFilenamePattern(): void
    {
        $validator = new Globstar(['*.test.js']);
        $this->assertTrue($validator->isValid('Button.test.js'));
        $this->assertTrue($validator->isValid('App.test.js'));
        $this->assertFalse($validator->isValid('ButtonXtestYjs'));
        $this->assertFalse($validator->isValid('src/Button.test.js'));
        $this->assertFalse($validator->isValid('Button.test.ts'));
    }

    // -------------------------------------------------------------------------
    // Prefix wildcard
    // -------------------------------------------------------------------------

    public function testPrefixWildcardBranchPattern(): void
    {
        $validator = new Globstar(['main*']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('main-extra'));
        $this->assertTrue($validator->isValid('mainline'));
        $this->assertFalse($validator->isValid('main/branch'));
        $this->assertFalse($validator->isValid('develop'));
    }

    // -------------------------------------------------------------------------
    // Deep nesting
    // -------------------------------------------------------------------------

    public function testDoubleWildcardInMiddleDeepNesting(): void
    {
        $validator = new Globstar(['a/**/b']);
        $this->assertTrue($validator->isValid('a/x/y/z/b'));
        $this->assertTrue($validator->isValid('a/p/q/r/s/b'));
        $this->assertTrue($validator->isValid('a/1/2/3/4/5/b'));
        $this->assertFalse($validator->isValid('a/x/y/z/b/extra'));
        $this->assertFalse($validator->isValid('prefix/a/x/b'));
    }

    public function testDoubleWildcardAtStartDeepNesting(): void
    {
        $validator = new Globstar(['**/README.md']);
        $this->assertTrue($validator->isValid('README.md'));
        $this->assertTrue($validator->isValid('docs/README.md'));
        $this->assertTrue($validator->isValid('a/b/c/d/README.md'));
        $this->assertTrue($validator->isValid('x/y/z/w/v/README.md'));
        $this->assertFalse($validator->isValid('a/b/c/README.md.bak'));
        $this->assertFalse($validator->isValid('a/b/c/README.md/extra'));
    }

    // -------------------------------------------------------------------------
    // Real-world path patterns
    // -------------------------------------------------------------------------

    public function testGeneratedFilesAnywhereExclusion(): void
    {
        $validator = new Globstar(['!**/generated/**']);
        $this->assertFalse($validator->isValid('generated/Foo.php'));
        $this->assertFalse($validator->isValid('src/generated/Foo.php'));
        $this->assertFalse($validator->isValid('src/api/generated/Bar.php'));
        $this->assertFalse($validator->isValid('generated/sub/deep/File.php'));
        $this->assertTrue($validator->isValid('src/components/Button.php'));
        $this->assertTrue($validator->isValid('main'));
    }

    public function testMultipleExtensionInclusions(): void
    {
        $validator = new Globstar(['**/*.php', '**/*.js']);
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
        $validator = new Globstar(['release/*']);
        $this->assertTrue($validator->isValid('release/1.0'));
        $this->assertTrue($validator->isValid('release/hotfix'));
        $this->assertTrue($validator->isValid('release/2024-01-15'));
        $this->assertFalse($validator->isValid('release/1.0/patch'));
        $this->assertFalse($validator->isValid('release'));
        $this->assertFalse($validator->isValid('main'));
    }

    // -------------------------------------------------------------------------
    // Case sensitivity
    // -------------------------------------------------------------------------

    public function testPatternMatchingIsCaseSensitive(): void
    {
        $branchValidator = new Globstar(['main']);
        $this->assertTrue($branchValidator->isValid('main'));
        $this->assertFalse($branchValidator->isValid('Main'));
        $this->assertFalse($branchValidator->isValid('MAIN'));

        $wildcardValidator = new Globstar(['feature/*']);
        $this->assertTrue($wildcardValidator->isValid('feature/foo'));
        $this->assertFalse($wildcardValidator->isValid('Feature/foo'));
        $this->assertFalse($wildcardValidator->isValid('FEATURE/foo'));
    }

    // -------------------------------------------------------------------------
    // Character class patterns
    // -------------------------------------------------------------------------

    public function testCharacterClassInclusion(): void
    {
        $validator = new Globstar(['[Mm]ain']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('Main'));
        $this->assertFalse($validator->isValid('MAIN'));
        $this->assertFalse($validator->isValid('develop'));
    }

    public function testCharacterClassInclusionWithWildcardExclusion(): void
    {
        // [Mm]ain is a character-class pattern (not a literal), so it must not
        // short-circuit before exclusions are evaluated.
        $validator = new Globstar(['[Mm]ain', '!**']);
        $this->assertFalse($validator->isValid('main'));
        $this->assertFalse($validator->isValid('Main'));
    }

    public function testCharacterClassExclusion(): void
    {
        $validator = new Globstar(['!feature/[0-9]*']);
        $this->assertFalse($validator->isValid('feature/123'));
        $this->assertFalse($validator->isValid('feature/9fix'));
        $this->assertTrue($validator->isValid('feature/abc'));
        $this->assertTrue($validator->isValid('main'));
    }

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    public function testValidatorMetadata(): void
    {
        $validator = new Globstar([]);
        $this->assertFalse($validator->isArray());
        $this->assertSame(\Utopia\Validator::TYPE_STRING, $validator->getType());
        $this->assertNotEmpty($validator->getDescription());
    }

    public function testRejectsNonStringValues(): void
    {
        $validator = new Globstar(['main']);
        $this->assertFalse($validator->isValid(123));
        $this->assertFalse($validator->isValid(null));
        $this->assertFalse($validator->isValid(['main']));
        $this->assertFalse($validator->isValid(true));
    }

    // -------------------------------------------------------------------------
    // Character class and escaped character coverage (gregpriday/gitignore-php parity)
    // -------------------------------------------------------------------------

    public function testLiteralsExact(): void
    {
        $this->assertTrue(new Globstar(['file.txt'])->isValid('file.txt'));
        $this->assertFalse(new Globstar(['file.txt'])->isValid('file.txt.bak'));
    }

    public function testSingleAsteriskDoesNotCrossSlash(): void
    {
        $this->assertTrue(new Globstar(['*.txt'])->isValid('file.txt'));
        $this->assertTrue(new Globstar(['*.txt'])->isValid('another.txt'));
        $this->assertFalse(new Globstar(['*.txt'])->isValid('file.txt.bak'));
        $this->assertFalse(new Globstar(['*.txt'])->isValid('dir/file.txt')); // * does not cross /
    }

    public function testQuestionMarkSingleChar(): void
    {
        $this->assertTrue(new Globstar(['file.?xt'])->isValid('file.txt'));
        $this->assertTrue(new Globstar(['file.?xt'])->isValid('file.dxt'));
        $this->assertFalse(new Globstar(['file.?xt'])->isValid('file.xtt'));
    }

    public function testDoubleStarPrefixFileMatch(): void
    {
        $this->assertTrue(new Globstar(['**/file.txt'])->isValid('file.txt'));
        $this->assertTrue(new Globstar(['**/file.txt'])->isValid('dir/file.txt'));
        $this->assertTrue(new Globstar(['**/file.txt'])->isValid('dir/subdir/file.txt'));
        $this->assertFalse(new Globstar(['**/file.txt'])->isValid('file.txt.bak'));
    }

    public function testDoubleStarMiddleFileMatch(): void
    {
        $this->assertTrue(new Globstar(['src/**/file.txt'])->isValid('src/file.txt'));
        $this->assertTrue(new Globstar(['src/**/file.txt'])->isValid('src/dir/file.txt'));
        $this->assertTrue(new Globstar(['src/**/file.txt'])->isValid('src/dir/subdir/file.txt'));
        $this->assertFalse(new Globstar(['src/**/file.txt'])->isValid('other/file.txt'));
    }

    public function testEscapedAsteriskIsLiteral(): void
    {
        $this->assertTrue(new Globstar(['file\*.txt'])->isValid('file*.txt'));
        $this->assertFalse(new Globstar(['file\*.txt'])->isValid('fileX.txt'));
    }

    public function testEscapedHashIsNotComment(): void
    {
        // \# must be treated as a literal # character, not a comment marker
        $this->assertTrue(new Globstar(['\#not_a_comment.txt'])->isValid('#not_a_comment.txt'));
    }

    public function testEscapedQuestionMarkIsLiteral(): void
    {
        // \? must match literal ? rather than any single character
        $this->assertTrue(new Globstar(['file\?.txt'])->isValid('file?.txt'));
    }

    public function testBasicCharacterClasses(): void
    {
        $this->assertTrue(new Globstar(['[a]bc.txt'])->isValid('abc.txt'));
        $this->assertFalse(new Globstar(['[a]bc.txt'])->isValid('bbc.txt'));
        $this->assertTrue(new Globstar(['[a-z]est.txt'])->isValid('test.txt'));
        $this->assertFalse(new Globstar(['[a-z]est.txt'])->isValid('Test.txt'));
        $this->assertTrue(new Globstar(['[A-Z]est.txt'])->isValid('Test.txt'));
        $this->assertFalse(new Globstar(['[A-Z]est.txt'])->isValid('test.txt'));
        $this->assertTrue(new Globstar(['file[0-9].log'])->isValid('file5.log'));
        $this->assertFalse(new Globstar(['file[0-9].log'])->isValid('fileA.log'));
        $this->assertTrue(new Globstar(['[a-zA-Z]file.txt'])->isValid('afile.txt'));
        $this->assertTrue(new Globstar(['[a-zA-Z]file.txt'])->isValid('Afile.txt'));
        $this->assertFalse(new Globstar(['[a-zA-Z]file.txt'])->isValid('1file.txt'));
    }

    public function testNegatedCharacterClasses(): void
    {
        $this->assertTrue(new Globstar(['[!a-z]file.txt'])->isValid('Afile.txt'));
        $this->assertFalse(new Globstar(['[!a-z]file.txt'])->isValid('afile.txt'));
        $this->assertTrue(new Globstar(['^[^a-z]file.txt'])->isValid('^1file.txt'));
        $this->assertFalse(new Globstar(['[^a-z]file.txt'])->isValid('afile.txt'));
        $this->assertTrue(new Globstar(['[!a-z0-9]file.txt'])->isValid('#file.txt'));
        $this->assertFalse(new Globstar(['[!a-z0-9]file.txt'])->isValid('afile.txt'));
        $this->assertFalse(new Globstar(['[!a-z0-9]file.txt'])->isValid('5file.txt'));
    }

    public function testCaretNegatedCharacterClass(): void
    {
        $this->assertTrue(new Globstar(['[^a-z]file.txt'])->isValid('1file.txt'));
        $this->assertFalse(new Globstar(['[^a-z]file.txt'])->isValid('afile.txt'));
    }

    public function testSpecialCharsInsideCharacterClasses(): void
    {
        $this->assertTrue(new Globstar(['file[.+]name.txt'])->isValid('file.name.txt'));
        $this->assertTrue(new Globstar(['file[.+]name.txt'])->isValid('file+name.txt'));
        $this->assertFalse(new Globstar(['file[.+]name.txt'])->isValid('filename.txt'));
        $this->assertTrue(new Globstar(['[_!@#]special.txt'])->isValid('_special.txt'));
        $this->assertTrue(new Globstar(['[_!@#]special.txt'])->isValid('@special.txt'));
        $this->assertFalse(new Globstar(['[_!@#]special.txt'])->isValid('xspecial.txt'));
        $this->assertTrue(new Globstar(['[-abc]dash.txt'])->isValid('-dash.txt'));
        $this->assertTrue(new Globstar(['[-abc]dash.txt'])->isValid('adash.txt'));
        $this->assertTrue(new Globstar(['[abc-]dash.txt'])->isValid('-dash.txt'));
    }

    public function testCharacterClassCombinedWithGlobstar(): void
    {
        $this->assertTrue(new Globstar(['[a-z]*.txt'])->isValid('abc.txt'));
        $this->assertFalse(new Globstar(['[a-z]*.txt'])->isValid('Abc.txt'));
        $this->assertTrue(new Globstar(['**/[a-z]*.txt'])->isValid('dir/abc.txt'));
        $this->assertTrue(new Globstar(['**/[a-z]*.txt'])->isValid('dir/subdir/abc.txt'));
        $this->assertFalse(new Globstar(['**/[a-z]*.txt'])->isValid('dir/Abc.txt'));
        $this->assertTrue(new Globstar(['[a-z][0-9]*.txt'])->isValid('a1file.txt'));
        $this->assertFalse(new Globstar(['[a-z][0-9]*.txt'])->isValid('ab.txt'));
        $this->assertFalse(new Globstar(['[a-z][0-9]*.txt'])->isValid('A1file.txt'));
    }

    public function testEdgeCaseEmptyCharacterClass(): void
    {
        // Empty character class [] matches nothing
        $this->assertFalse(new Globstar(['[]file.txt'])->isValid('file.txt'));
    }

    public function testEdgeCaseUnclosedBracket(): void
    {
        // Unclosed bracket treated as literal
        $this->assertTrue(new Globstar(['[abc'])->isValid('[abc'));
        $this->assertFalse(new Globstar(['[abc'])->isValid('abc'));
    }

    public function testEdgeCaseExclamationOnlyClass(): void
    {
        // [!] — exclamation as sole content; behaviour is implementation-defined
        // but the implementation treats it as a literal class containing '!'
        $this->assertTrue(new Globstar(['[!]file.txt'])->isValid('!file.txt'));
    }

    public function testEdgeCaseCaretOnlyClass(): void
    {
        // [^] — caret as sole content; behaviour is implementation-defined
        // but the implementation treats it as a literal class containing '^'
        $this->assertTrue(new Globstar(['[^]file.txt'])->isValid('^file.txt'));
    }

    // -------------------------------------------------------------------------
    // Complex negation chain
    // -------------------------------------------------------------------------

    public function testComplexNegationChainPattern(): void
    {
        // Patterns: *.md, !README*.md, README-private*.md
        // - inclusion exists (*.md), so non-matching paths fail
        // - README*.md is excluded by the second pattern
        // - README-private*.md is re-included by the third pattern
        $patterns = ['*.md', '!README*.md', 'README-private*.md'];

        $this->assertTrue(new Globstar($patterns)->isValid('documentation.md'));      // included, not excluded
        $this->assertFalse(new Globstar($patterns)->isValid('README.md'));             // excluded by !README*.md
        $this->assertFalse(new Globstar($patterns)->isValid('README-public.md'));      // excluded by !README*.md
        $this->assertTrue(new Globstar($patterns)->isValid('README-private.md'));      // re-included by README-private*.md
        $this->assertTrue(new Globstar($patterns)->isValid('README-private-draft.md')); // re-included by README-private*.md
    }
}
