<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Appwrite\Auth\JobsSecret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobsSecretTest extends TestCase
{
    #[DataProvider('insecureSecrets')]
    public function testIsInsecure(?string $secret, bool $expected): void
    {
        $this->assertSame($expected, JobsSecret::isInsecure($secret));
    }

    /**
     * @return \Iterator<string, array{0: ?string, 1: bool}>
     */
    public static function insecureSecrets(): \Iterator
    {
        yield 'null' => [null, true];
        yield 'empty' => ['', true];
        yield 'placeholder' => [JobsSecret::PLACEHOLDER, true];
        yield 'unique' => ['a-unique-jobs-secret', false];
    }

    #[DataProvider('rejectedSecrets')]
    public function testVerifyRejectsInsecureSecret(?string $secret): void
    {
        $body = '{"id":"job-1"}';
        $key = $secret ?? '';
        $signature = 'sha256=' . \hash_hmac('sha256', $body, $key);

        $this->assertFalse(JobsSecret::verify($body, $signature, $secret));
    }

    /**
     * @return \Iterator<string, array{0: ?string}>
     */
    public static function rejectedSecrets(): \Iterator
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'placeholder' => [JobsSecret::PLACEHOLDER];
    }

    #[DataProvider('insecureInstallSecrets')]
    public function testResolveReplacesInsecureSecret(string $secret): void
    {
        $resolved = JobsSecret::resolve($secret);

        $this->assertFalse(JobsSecret::isInsecure($resolved));
        $this->assertNotSame($secret, $resolved);
    }

    /**
     * @return \Iterator<string, array{0: string}>
     */
    public static function insecureInstallSecrets(): \Iterator
    {
        yield 'empty' => [''];
        yield 'placeholder' => [JobsSecret::PLACEHOLDER];
    }

    public function testResolveKeepsUniqueSecret(): void
    {
        $this->assertSame('operator-chosen-jobs-secret', JobsSecret::resolve('operator-chosen-jobs-secret'));
    }

    public function testVerifyAcceptsCustomSecret(): void
    {
        $secret = 'operator-chosen-jobs-secret';
        $body = '{"id":"job-1"}';
        $signature = 'sha256=' . \hash_hmac('sha256', $body, $secret);

        $this->assertTrue(JobsSecret::verify($body, $signature, $secret));
    }

    public function testVerifyRejectsWrongSignatureForCustomSecret(): void
    {
        $secret = 'operator-chosen-jobs-secret';
        $body = '{"id":"job-1"}';
        $signature = 'sha256=' . \hash_hmac('sha256', $body, 'other-secret');

        $this->assertFalse(JobsSecret::verify($body, $signature, $secret));
    }

    public function testAssertProductionRejectsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('_APP_JOBS_SECRET is missing');

        JobsSecret::assertProduction('production', '');
    }

    public function testAssertProductionRejectsMissing(): void
    {
        $this->expectException(\RuntimeException::class);

        JobsSecret::assertProduction('production', null);
    }

    public function testAssertProductionAllowsUniqueAndDevelopment(): void
    {
        JobsSecret::assertProduction('production', 'operator-chosen-jobs-secret');
        JobsSecret::assertProduction('development', '');
        JobsSecret::assertProduction('development', JobsSecret::DEVELOPMENT);

        $this->expectNotToPerformAssertions();
    }

    public function testDevelopmentValueIsTheRepositoryOne(): void
    {
        // The checked-in .env must carry exactly the value production warns about
        $env = \file_get_contents(__DIR__ . '/../../../.env');
        $this->assertNotFalse($env);
        $this->assertStringContainsString('_APP_JOBS_SECRET=' . JobsSecret::DEVELOPMENT . "\n", $env);
    }
}
