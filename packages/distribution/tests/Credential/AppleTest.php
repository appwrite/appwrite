<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Credential;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Credential\Apple;
use Utopia\Distribution\Exception\Invalid;

final class AppleTest extends TestCase
{
    private const string KEY = "-----BEGIN PRIVATE KEY-----\nkey\n-----END PRIVATE KEY-----";

    public function testValidCredential(): void
    {
        $credential = new Apple('ABC123DEFG', '69a6de70-0000-47e3-e053-5b8c7c11a4d1', self::KEY);

        $this->assertInstanceOf(Credential::class, $credential);
        $this->assertSame('ABC123DEFG', $credential->keyId);
        $this->assertSame('69a6de70-0000-47e3-e053-5b8c7c11a4d1', $credential->issuerId);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $credential->privateKey);
    }

    public function testEmptyIssuerIdThrows(): void
    {
        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('individual keys are not supported');

        new Apple('ABC123DEFG', '', 'key');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function empty(): iterable
    {
        yield 'key id' => ['', 'issuer', 'key'];
        yield 'private key' => ['ABC123DEFG', 'issuer', ''];
    }

    #[DataProvider('empty')]
    public function testEmptyArgumentThrows(string $keyId, string $issuerId, string $privateKey): void
    {
        $this->expectException(Invalid::class);

        new Apple($keyId, $issuerId, $privateKey);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPem(): iterable
    {
        yield 'text' => ['key'];
        yield 'path' => ['file:///keys/AuthKey_ABC123DEFG.p8'];
        yield 'path with marker' => ["file:///keys/AuthKey.p8\n-----BEGIN PRIVATE KEY-----"];
    }

    #[DataProvider('nonPem')]
    public function testNonPemPrivateKeyThrows(string $privateKey): void
    {
        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('PEM text');

        new Apple('ABC123DEFG', 'issuer', $privateKey);
    }

    public function testDebugOutputRedactsPrivateKey(): void
    {
        $credential = new Apple('ABC123DEFG', 'issuer', self::KEY);

        ob_start();
        var_dump($credential);
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString('PRIVATE KEY', $dump);
        $this->assertStringNotContainsString('PRIVATE KEY', print_r($credential, true));
        $this->assertStringContainsString('ABC123DEFG', $dump);
    }
}
