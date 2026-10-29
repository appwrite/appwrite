<?php

declare(strict_types=1);

namespace Utopia\Validator\Tests;

use PHPStan\Rules\RestrictedUsage\RestrictedMethodUsageExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Utopia\Validator\PHPStan\DisallowAssertEqualsExtension;
use Utopia\Validator\Text;
use Utopia\Validator\Validator;

final class CompatTest extends TestCase
{
    public function testOldBaseClassNameResolvesToTheMovedClass(): void
    {
        $old = \Utopia\Validator::class;
        if (!class_exists($old)) {
            $this->fail($old . ' does not resolve');
        }

        $this->assertSame(Validator::class, new \ReflectionClass($old)->getName());
    }

    public function testValidatorsWrittenAgainstTheOldNameAreValidators(): void
    {
        $validator = new OldValidator();

        $this->assertInstanceOf(Validator::class, $validator);
        $this->assertTrue($validator->isValid('old'));
        $this->assertInstanceOf(\Utopia\Validator::class, new Text(10));
    }

    /**
     * PHP never autoloads a name while checking a declared type, so a
     * consumer typed against the old name accepts the moved class only if
     * the alias exists before any code mentions it. A fresh process keeps
     * the earlier tests from registering it first.
     */
    #[RunInSeparateProcess]
    public function testOldNameInTypeDeclarationsAcceptsTheMovedClasses(): void
    {
        $consumer = new OldNamesConsumer(new Text(3));

        $this->assertTrue($consumer->check('abc'));
        $this->assertFalse($consumer->check('abcd'));
    }

    public function testOldPHPStanExtensionNameResolvesWhenPHPStanIsInstalled(): void
    {
        if (!interface_exists(RestrictedMethodUsageExtension::class)) {
            $this->markTestSkipped('PHPStan is not installed.');
        }

        $old = 'Utopia\PHPStan\DisallowAssertEqualsExtension';
        if (!class_exists($old)) {
            $this->fail($old . ' does not resolve');
        }

        $this->assertSame(DisallowAssertEqualsExtension::class, new \ReflectionClass($old)->getName());
    }
}
