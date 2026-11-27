<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Signing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Signing\Certificate;
use Utopia\Distribution\Signing\Keystore;

final class KeystoreTest extends TestCase
{
    private const array SUBJECT = ['commonName' => 'Example Upload Key', 'organizationName' => 'Example'];

    private const string ALIAS = 'upload';

    private const string PLAY_DEADLINE = '2033-10-22T00:00:00+00:00';

    public function testGenerateSubjectHasOnlyRequestedFields(): void
    {
        $keystore = Keystore::generate(self::ALIAS, self::SUBJECT);

        $this->assertSame(['CN' => 'Example Upload Key', 'O' => 'Example'], $keystore->certificate->subject());
        $this->assertSame(['CN' => 'Example Upload Key', 'O' => 'Example'], openssl_x509_parse($keystore->certificate->pem)['issuer'] ?? null);
    }

    public function testGenerateOutlastsPlayDeadlineByThirtyYears(): void
    {
        $keystore = Keystore::generate(self::ALIAS, self::SUBJECT);

        $expiresAt = $keystore->certificate->expiresAt();
        $this->assertGreaterThan(new \DateTimeImmutable(self::PLAY_DEADLINE), $expiresAt);
        $this->assertEqualsWithDelta(
            new \DateTimeImmutable('+' . Keystore::YEARS . ' years')->getTimestamp(),
            $expiresAt->getTimestamp(),
            86400,
        );
    }

    public function testGenerateHonoursYears(): void
    {
        $keystore = Keystore::generate(self::ALIAS, self::SUBJECT, 2);

        $this->assertEqualsWithDelta(new \DateTimeImmutable('+2 years')->getTimestamp(), $keystore->certificate->expiresAt()->getTimestamp(), 86400);
    }

    public function testGenerateArchiveHoldsRsa2048KeyUnderOnePassword(): void
    {
        $keystore = Keystore::generate(self::ALIAS, self::SUBJECT);

        $parts = [];
        $this->assertTrue(openssl_pkcs12_read($keystore->archive, $parts, $keystore->password));
        $this->assertIsArray($parts);
        $this->assertIsString($parts['cert']);
        $this->assertIsString($parts['pkey']);
        $this->assertSame($keystore->certificate->fingerprint(), openssl_x509_fingerprint($parts['cert'], 'sha256'));

        $key = openssl_pkey_get_private($parts['pkey']);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $this->assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        $this->assertSame(2048, $details['bits']);
        $this->assertTrue(openssl_x509_check_private_key($parts['cert'], $key));
    }

    public function testGenerateSetsAliasAsFriendlyName(): void
    {
        $keystore = Keystore::generate(self::ALIAS, self::SUBJECT);

        $this->assertSame(self::ALIAS, $keystore->alias);
        $this->assertStringContainsString(implode('', array_map(static fn (string $character): string => "\0{$character}", str_split(self::ALIAS))), $keystore->archive);
    }

    public function testGeneratePasswordsAreRandom(): void
    {
        $first = Keystore::generate(self::ALIAS, self::SUBJECT, 1);
        $second = Keystore::generate(self::ALIAS, self::SUBJECT, 1);

        $this->assertGreaterThanOrEqual(32, \strlen($first->password));
        $this->assertNotSame($first->password, $second->password);
        $this->assertNotSame($first->certificate->fingerprint(), $second->certificate->fingerprint());
    }

    public function testGenerateWithoutYearsThrows(): void
    {
        $this->expectException(Invalid::class);

        Keystore::generate(self::ALIAS, self::SUBJECT, 0);
    }

    public function testGenerateWithoutAliasThrows(): void
    {
        $this->expectException(Invalid::class);

        Keystore::generate('', self::SUBJECT, 1);
    }

    public function testFromPkcs12RoundTrip(): void
    {
        $generated = Keystore::generate(self::ALIAS, self::SUBJECT, 1);

        $keystore = Keystore::fromPkcs12($generated->archive, $generated->password, self::ALIAS);

        $this->assertSame($generated->archive, $keystore->archive);
        $this->assertSame(self::ALIAS, $keystore->alias);
        $this->assertSame($generated->certificate->fingerprint(), $keystore->certificate->fingerprint());
    }

    public function testFromPkcs12WrongPasswordThrows(): void
    {
        $generated = Keystore::generate(self::ALIAS, self::SUBJECT, 1);

        $this->expectException(Invalid::class);

        Keystore::fromPkcs12($generated->archive, 'wrong', self::ALIAS);
    }

    public function testIsJks(): void
    {
        $generated = Keystore::generate(self::ALIAS, self::SUBJECT, 1);

        $this->assertTrue(Keystore::isJks(Keystore::JKS . random_bytes(32)));
        $this->assertFalse(Keystore::isJks(Keystore::JCEKS . random_bytes(32)));
        $this->assertFalse(Keystore::isJks($generated->archive));
        $this->assertFalse(Keystore::isJks(''));
        $this->assertFalse(Keystore::isJks("\xFE\xED"));
    }

    public function testIsJceks(): void
    {
        $generated = Keystore::generate(self::ALIAS, self::SUBJECT, 1);

        $this->assertTrue(Keystore::isJceks(Keystore::JCEKS . random_bytes(32)));
        $this->assertFalse(Keystore::isJceks(Keystore::JKS . random_bytes(32)));
        $this->assertFalse(Keystore::isJceks($generated->archive));
        $this->assertFalse(Keystore::isJceks(''));
        $this->assertFalse(Keystore::isJceks("\xCE\xCE"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function javaKeystores(): iterable
    {
        yield 'jks' => [Keystore::JKS];
        yield 'jceks' => [Keystore::JCEKS];
    }

    #[DataProvider('javaKeystores')]
    public function testFromPkcs12RejectsJavaKeystore(string $magic): void
    {
        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('JKS or JCEKS');

        Keystore::fromPkcs12($magic . "\x00\x00\x00\x02" . random_bytes(64), 'secret', self::ALIAS);
    }

    public function testFromPkcs12LegacyAsksForReexport(): void
    {
        if (\in_array(Legacy::RC2, openssl_get_cipher_methods(), true)) {
            $this->markTestSkipped('The OpenSSL legacy provider is loaded, so legacy archives are readable');
        }

        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('legacy encryption');

        Keystore::fromPkcs12(Legacy::pkcs12('secret'), 'secret', self::ALIAS);
    }

    public function testDebugOutputRedactsPassword(): void
    {
        $keystore = Keystore::generate(self::ALIAS, self::SUBJECT, 1);

        ob_start();
        var_dump($keystore);
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString($keystore->password, $dump);
        $this->assertStringNotContainsString($keystore->password, print_r($keystore, true));
        $this->assertStringContainsString(self::ALIAS, print_r($keystore, true));
    }

    public function testEmptyArchiveThrows(): void
    {
        $generated = Keystore::generate(self::ALIAS, self::SUBJECT, 1);

        $this->expectException(Invalid::class);

        new Keystore('', 'secret', self::ALIAS, $generated->certificate);
    }

    public function testGeneratedArchiveIsReadableAsCertificate(): void
    {
        $generated = Keystore::generate(self::ALIAS, self::SUBJECT, 1);

        $this->assertSame($generated->certificate->pem, Certificate::fromPkcs12($generated->archive, $generated->password)->pem);
    }
}
