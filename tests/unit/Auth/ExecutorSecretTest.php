<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Appwrite\Auth\ExecutorSecret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExecutorSecretTest extends TestCase
{
    #[DataProvider('insecureSecrets')]
    public function testIsInsecure(?string $secret, bool $expected): void
    {
        $this->assertSame($expected, ExecutorSecret::isInsecure($secret));
    }

    /**
     * @return \Iterator<string, array{0: ?string, 1: bool}>
     */
    public static function insecureSecrets(): \Iterator
    {
        yield 'null' => [null, true];
        yield 'empty' => ['', true];
        yield 'placeholder' => [ExecutorSecret::PLACEHOLDER, true];
        yield 'unique' => ['a-unique-executor-secret', false];
    }

    #[DataProvider('insecureInstallSecrets')]
    public function testResolveReplacesInsecureSecret(string $secret): void
    {
        $resolved = ExecutorSecret::resolve($secret);

        $this->assertFalse(ExecutorSecret::isInsecure($resolved));
        $this->assertNotSame($secret, $resolved);
        $this->assertNotSame($resolved, ExecutorSecret::resolve($secret));
    }

    /**
     * @return \Iterator<string, array{0: string}>
     */
    public static function insecureInstallSecrets(): \Iterator
    {
        yield 'empty' => [''];
        yield 'placeholder' => [ExecutorSecret::PLACEHOLDER];
    }

    public function testResolveKeepsUniqueSecret(): void
    {
        $this->assertSame('operator-chosen-executor-secret', ExecutorSecret::resolve('operator-chosen-executor-secret'));
    }

    public function testAssertProductionRejectsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('_APP_EXECUTOR_SECRET is missing');

        ExecutorSecret::assertProduction('production', '');
    }

    public function testAssertProductionRejectsMissing(): void
    {
        $this->expectException(\RuntimeException::class);

        ExecutorSecret::assertProduction('production', null);
    }

    public function testAssertProductionAllowsUniqueAndDevelopment(): void
    {
        ExecutorSecret::assertProduction('production', 'a-unique-executor-secret');
        ExecutorSecret::assertProduction('development', '');
        ExecutorSecret::assertProduction('development', ExecutorSecret::PLACEHOLDER);

        $this->expectNotToPerformAssertions();
    }
}
