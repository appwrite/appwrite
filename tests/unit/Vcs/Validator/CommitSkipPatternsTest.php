<?php

namespace Tests\Unit\Vcs\Validator;

use Appwrite\Vcs\Validator\CommitSkipPatterns;
use PHPUnit\Framework\TestCase;

class CommitSkipPatternsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Empty patterns — never skip
    // -------------------------------------------------------------------------

    public function testEmptyPatternsNeverSkip(): void
    {
        $validator = new CommitSkipPatterns([]);
        $this->assertTrue($validator->isValid('fix: update readme'));
        $this->assertTrue($validator->isValid('[skip deploy] docs only'));
        $this->assertTrue($validator->isValid(''));
    }

    // -------------------------------------------------------------------------
    // Single pattern — directive match
    // -------------------------------------------------------------------------

    public function testSinglePatternMatchSkips(): void
    {
        $validator = new CommitSkipPatterns(['[skip deploy]']);
        $this->assertFalse($validator->isValid('[skip deploy] docs only'));
        $this->assertFalse($validator->isValid('chore: update deps [skip deploy]'));
        $this->assertFalse($validator->isValid('prefix [skip deploy] suffix'));
    }

    public function testSinglePatternNoMatchProceeds(): void
    {
        $validator = new CommitSkipPatterns(['[skip deploy]']);
        $this->assertTrue($validator->isValid('fix: real bug fix'));
        $this->assertTrue($validator->isValid('feat: add new feature'));
        $this->assertTrue($validator->isValid('skip deploy without brackets'));
        $this->assertTrue($validator->isValid('prefix[skip deploy]suffix'));
    }

    // -------------------------------------------------------------------------
    // Case insensitivity
    // -------------------------------------------------------------------------

    public function testCaseInsensitiveMatch(): void
    {
        $validator = new CommitSkipPatterns(['[skip deploy]']);
        $this->assertFalse($validator->isValid('[SKIP DEPLOY] uppercase'));
        $this->assertFalse($validator->isValid('[Skip Deploy] mixed case'));
        $this->assertFalse($validator->isValid('[skip DEPLOY] partial upper'));
    }

    public function testPatternItselfCaseInsensitive(): void
    {
        $validator = new CommitSkipPatterns(['[SKIP DEPLOY]']);
        $this->assertFalse($validator->isValid('[skip deploy] lowercase message'));
        $this->assertFalse($validator->isValid('[Skip Deploy] mixed message'));
    }

    // -------------------------------------------------------------------------
    // Array of patterns — any match skips (OR semantics)
    // -------------------------------------------------------------------------

    public function testMultiplePatternsFirstMatches(): void
    {
        $validator = new CommitSkipPatterns(['[skip deploy]', '[skip ci]', '[no deploy]']);
        $this->assertFalse($validator->isValid('[skip deploy] docs only'));
    }

    public function testMultiplePatternsSecondMatches(): void
    {
        $validator = new CommitSkipPatterns(['[skip deploy]', '[skip ci]', '[no deploy]']);
        $this->assertFalse($validator->isValid('chore: update readme [skip ci]'));
    }

    public function testMultiplePatternsThirdMatches(): void
    {
        $validator = new CommitSkipPatterns(['[skip deploy]', '[skip ci]', '[no deploy]']);
        $this->assertFalse($validator->isValid('[no deploy] just docs'));
    }

    public function testMultiplePatternsNoneMatchProceeds(): void
    {
        $validator = new CommitSkipPatterns(['[skip deploy]', '[skip ci]', '[no deploy]']);
        $this->assertTrue($validator->isValid('feat: completely new feature'));
        $this->assertTrue($validator->isValid('fix: important bug fix'));
    }

    // -------------------------------------------------------------------------
    // Common real-world skip conventions
    // -------------------------------------------------------------------------

    public function testCommonSkipCiPattern(): void
    {
        $validator = new CommitSkipPatterns(['[skip ci]']);
        $this->assertFalse($validator->isValid('[skip ci] update changelog'));
        $this->assertFalse($validator->isValid('[SKIP CI]'));
        $this->assertTrue($validator->isValid('feat: something real'));
    }

    public function testNoDeployPattern(): void
    {
        $validator = new CommitSkipPatterns(['[no deploy]']);
        $this->assertFalse($validator->isValid('[no deploy] tweak docs'));
        $this->assertTrue($validator->isValid('deploy this please'));
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function testEmptyCommitMessageNeverSkipsWithPatterns(): void
    {
        $validator = new CommitSkipPatterns(['[skip deploy]']);
        $this->assertTrue($validator->isValid(''));
    }

    public function testBlankPatternsInArrayAreIgnored(): void
    {
        $validator = new CommitSkipPatterns(['', '  ', '[skip deploy]']);
        $this->assertTrue($validator->isValid('normal commit message'));
        $this->assertFalse($validator->isValid('[skip deploy] docs'));
    }

    public function testPatternMustBeStandaloneDirective(): void
    {
        $validator = new CommitSkipPatterns(['[skip deploy]']);
        $this->assertTrue($validator->isValid('skippy the kangaroo'));
        $this->assertTrue($validator->isValid('prefix[skip deploy]suffix'));
    }

    public function testMultilineCommitMessage(): void
    {
        $validator = new CommitSkipPatterns(['[skip deploy]']);
        $msg = "feat: add new stuff\n\nMore detail here.\n\n[skip deploy]";
        $this->assertFalse($validator->isValid($msg));
    }

    public function testWhitespaceInsideDirectiveIsNormalized(): void
    {
        $validator = new CommitSkipPatterns([' [skip   deploy] ']);
        $this->assertFalse($validator->isValid('[skip deploy] docs only'));
        $this->assertFalse($validator->isValid('[SKIP   DEPLOY] docs only'));
    }

    public function testTrailerDirectiveCanSkip(): void
    {
        $validator = new CommitSkipPatterns(['skip-checks: true']);
        $msg = "feat: add new stuff\n\nMore detail here.\n\nskip-checks:true";
        $this->assertFalse($validator->isValid($msg));
    }
}
