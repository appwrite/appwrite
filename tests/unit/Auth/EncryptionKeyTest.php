<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Appwrite\Auth\EncryptionKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EncryptionKeyTest extends TestCase
{
    #[DataProvider('insecureKeys')]
    public function testIsInsecure(?string $key, bool $expected): void
    {
        $this->assertSame($expected, EncryptionKey::isInsecure($key));
    }

    /**
     * @return \Iterator<string, array{0: ?string, 1: bool}>
     */
    public static function insecureKeys(): \Iterator
    {
        yield 'null' => [null, true];
        yield 'empty' => ['', true];
        yield 'placeholder' => [EncryptionKey::PLACEHOLDER, true];
        yield 'unique' => ['a-unique-generated-secret', false];
    }

    public function testAssertProductionAllowsPlaceholder(): void
    {
        EncryptionKey::assertProduction('production', EncryptionKey::PLACEHOLDER);
        $this->addToAssertionCount(1);
    }

    public function testAssertProductionRejectsEmpty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('_APP_OPENSSL_KEY_V1');

        EncryptionKey::assertProduction('production', '');
    }

    public function testAssertProductionRejectsMissing(): void
    {
        $this->expectException(RuntimeException::class);

        EncryptionKey::assertProduction('production', null);
    }

    public function testAssertProductionAllowsDevelopmentPlaceholder(): void
    {
        EncryptionKey::assertProduction('development', EncryptionKey::PLACEHOLDER);
        $this->addToAssertionCount(1);
    }

    public function testAssertProductionAllowsUniqueKey(): void
    {
        EncryptionKey::assertProduction('production', 'generated-unique-key');
        $this->addToAssertionCount(1);
    }
}
