<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Auth\EncryptionKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EncryptionKeyTest extends TestCase
{
    private const array VARIABLES = ['_APP_OPENSSL_KEY_V1', '_APP_OPENSSL_KEY_V2', '_APP_OPENSSL_KEY_CURRENT', '_APP_OPENSSL_KEY_OLDEST'];

    /**
     * @var array<string, string|false>
     */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->environment[$name] = \getenv($name);
            \putenv($name);
        }
        \putenv('_APP_OPENSSL_KEY_V1=first-key');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            \putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testCurrentDefaultsToFirstVersion(): void
    {
        $this->assertSame('1', EncryptionKey::version());
        $this->assertSame('first-key', EncryptionKey::current());
        $this->assertSame(['first-key'], EncryptionKey::accepted());
    }

    public function testCurrentFollowsConfiguredVersion(): void
    {
        \putenv('_APP_OPENSSL_KEY_V2=second-key');
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');

        $this->assertSame('second-key', EncryptionKey::current());
        $this->assertSame(['second-key', 'first-key'], EncryptionKey::accepted());
    }

    public function testNextKeyIsAcceptedBeforeItIsCurrent(): void
    {
        \putenv('_APP_OPENSSL_KEY_V2=second-key');

        // Servers still on version 1 accept tokens from servers already on version 2
        $this->assertSame('first-key', EncryptionKey::current());
        $this->assertSame(['first-key', 'second-key'], EncryptionKey::accepted());
        $this->assertSame('user', EncryptionKey::decode(self::token('second-key'))['userId']);
    }

    public function testDecodeAcceptsCurrentKey(): void
    {
        \putenv('_APP_OPENSSL_KEY_V2=second-key');
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');

        $this->assertSame('user', EncryptionKey::decode(self::token(EncryptionKey::current()))['userId']);
    }

    public function testDecodeAcceptsOldKeyUntilRetired(): void
    {
        \putenv('_APP_OPENSSL_KEY_V2=second-key');
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');

        $this->assertSame('user', EncryptionKey::decode(self::token('first-key'))['userId']);
    }

    public function testDecodeRefusesRetiredKey(): void
    {
        \putenv('_APP_OPENSSL_KEY_V2=second-key');
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');
        \putenv('_APP_OPENSSL_KEY_OLDEST=2');

        $this->assertSame(['second-key'], EncryptionKey::accepted());
        $this->assertSame('first-key', EncryptionKey::get('1'), 'A retired key still decrypts stored data');

        $this->expectException(JWTException::class);
        EncryptionKey::decode(self::token('first-key'));
    }

    public function testDecodeRefusesForeignKey(): void
    {
        $this->expectException(JWTException::class);
        $this->expectExceptionCode(JWT::ERROR_SIGNATURE_FAILED);

        EncryptionKey::decode(self::token('attacker-key'));
    }

    public function testDecodeReportsExpiryOfOldKey(): void
    {
        \putenv('_APP_OPENSSL_KEY_V2=second-key');
        \putenv('_APP_OPENSSL_KEY_CURRENT=2');

        $this->expectException(JWTException::class);
        $this->expectExceptionCode(JWT::ERROR_TOKEN_EXPIRED);

        EncryptionKey::decode(self::token('first-key', issued: \time() - 7200));
    }

    private static function token(string $key, ?int $issued = null): string
    {
        return (new JWT($key, 'HS256', 3600, 0))->setTestTimestamp($issued)->encode(['userId' => 'user']);
    }

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

    #[DataProvider('insecureInstallKeys')]
    public function testResolveGeneratesOnFreshInstall(string $key): void
    {
        $resolved = EncryptionKey::resolve($key, generate: true);

        $this->assertFalse(EncryptionKey::isInsecure($resolved));
    }

    /**
     * @return \Iterator<string, array{0: string}>
     */
    public static function insecureInstallKeys(): \Iterator
    {
        yield 'empty' => [''];
        yield 'placeholder' => [EncryptionKey::PLACEHOLDER];
    }

    public function testResolveKeepsUniqueKey(): void
    {
        $this->assertSame('operator-chosen-secret', EncryptionKey::resolve('operator-chosen-secret', generate: true));
    }

    #[DataProvider('existingKeys')]
    public function testResolveKeepsExistingKeyOnUpgrade(string $key): void
    {
        $this->assertSame($key, EncryptionKey::resolve($key, generate: false), 'Rewriting an existing key would make its encrypted data unreadable');
    }

    /**
     * @return \Iterator<string, array{0: string}>
     */
    public static function existingKeys(): \Iterator
    {
        yield 'placeholder' => [EncryptionKey::PLACEHOLDER];
        yield 'unique' => ['existing-installation-secret'];
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
