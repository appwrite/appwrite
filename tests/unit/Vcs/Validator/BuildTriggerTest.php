<?php

namespace Tests\Unit\Vcs\Validator;

use Appwrite\Vcs\Validator\BuildTrigger;
use PHPUnit\Framework\TestCase;

class BuildTriggerTest extends TestCase
{
    public function testEmptyPatterns(): void
    {
        $validator = new BuildTrigger([]);
        $this->assertTrue($validator->isValid('anything'));
        $this->assertTrue($validator->isValid('main'));
    }

    public function testExactMatch(): void
    {
        $validator = new BuildTrigger(['main']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertFalse($validator->isValid('develop'));
    }

    public function testSingleWildcard(): void
    {
        $validator = new BuildTrigger(['feature/*']);
        $this->assertTrue($validator->isValid('feature/foo'));
        $this->assertFalse($validator->isValid('feature/foo/bar')); // * does not cross /
        $this->assertFalse($validator->isValid('main'));
    }

    public function testDashInPattern(): void
    {
        $validator = new BuildTrigger(['feature/test-*']);
        $this->assertTrue($validator->isValid('feature/test-1'));
        $this->assertTrue($validator->isValid('feature/test-abc'));
        $this->assertFalse($validator->isValid('feature/other'));
    }

    public function testDoubleWildcardEnd(): void
    {
        $validator = new BuildTrigger(['src/**']);
        $this->assertTrue($validator->isValid('src/foo.js'));
        $this->assertTrue($validator->isValid('src/a/b/c.js'));
    }

    public function testDoubleWildcardMiddle(): void
    {
        $validator = new BuildTrigger(['a/**/b']);
        $this->assertTrue($validator->isValid('a/b'));     // zero intermediate dirs
        $this->assertTrue($validator->isValid('a/x/b'));   // one
        $this->assertTrue($validator->isValid('a/x/y/b')); // two
        $this->assertFalse($validator->isValid('a/b/c'));
    }

    public function testDoubleWildcardStart(): void
    {
        $validator = new BuildTrigger(['**/foo']);
        $this->assertTrue($validator->isValid('foo'));
        $this->assertTrue($validator->isValid('a/foo'));
        $this->assertTrue($validator->isValid('a/b/foo'));
        $this->assertFalse($validator->isValid('foobar'));
    }

    public function testOrSemantics(): void
    {
        $validator = new BuildTrigger(['main', 'develop']);
        $this->assertTrue($validator->isValid('main'));
        $this->assertTrue($validator->isValid('develop'));
        $this->assertFalse($validator->isValid('feature/x'));
    }

    public function testInclusionTakesPrecedenceOverExclusion(): void
    {
        $validator = new BuildTrigger(['!feature/*', 'feature/abc']);
        $this->assertTrue($validator->isValid('feature/abc'));  // inclusion wins
        $this->assertFalse($validator->isValid('feature/xyz')); // excluded
    }

    public function testOnlyExclusions(): void
    {
        $validator = new BuildTrigger(['!main']);
        $this->assertFalse($validator->isValid('main'));
        $this->assertTrue($validator->isValid('develop')); // not excluded, passes by default
    }
}
