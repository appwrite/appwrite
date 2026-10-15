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
        $key = $secret === null || $secret === '' ? 'unused' : $secret;
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
}
