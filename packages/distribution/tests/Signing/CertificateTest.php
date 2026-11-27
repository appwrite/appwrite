<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Signing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Signing\Certificate;

final class CertificateTest extends TestCase
{
    private const array SUBJECT = [
        'commonName' => 'Apple Distribution: Example Team (ABCDE12345)',
        'organizationName' => 'Example Team',
        'organizationalUnitName' => 'ABCDE12345',
        'countryName' => 'US',
    ];

    public function testKeyIsRsa2048(): void
    {
        $details = $this->details(Certificate::key());

        $this->assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        $this->assertSame(2048, $details['bits']);
    }

    public function testRequestSubjectHasOnlyRequestedFields(): void
    {
        $request = Certificate::request(Certificate::key(), ['commonName' => 'Example Distribution']);

        $this->assertStringStartsWith('-----BEGIN CERTIFICATE REQUEST-----', $request);
        $this->assertSame(['CN' => 'Example Distribution'], openssl_csr_get_subject($request));
    }

    public function testRequestSubjectKeepsEveryRequestedField(): void
    {
        $request = Certificate::request(Certificate::key(), self::SUBJECT);

        $this->assertSame(
            ['CN' => self::SUBJECT['commonName'], 'O' => 'Example Team', 'OU' => 'ABCDE12345', 'C' => 'US'],
            openssl_csr_get_subject($request),
        );
    }

    public function testRequestIsSignedWithSha256ByTheKey(): void
    {
        $key = Certificate::key();
        $request = Certificate::request($key, ['commonName' => 'Example Distribution']);

        $text = null;
        $this->assertTrue(openssl_csr_export($request, $text, false));
        $this->assertIsString($text);
        $this->assertStringContainsString('sha256WithRSAEncryption', $text);

        $public = openssl_csr_get_public_key($request);
        $this->assertNotFalse($public);
        $this->assertSame($this->details($key)['key'], openssl_pkey_get_details($public)['key'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function subjects(): iterable
    {
        yield 'empty' => [[]];
        yield 'empty value' => [['commonName' => '']];
        yield 'unknown field' => [['unknownField' => 'value']];
        yield 'country too long' => [['countryName' => 'USA']];
    }

    /**
     * @param array<string, string> $subject
     */
    #[DataProvider('subjects')]
    public function testInvalidRequestSubjectThrows(array $subject): void
    {
        $this->expectException(Invalid::class);

        Certificate::request(Certificate::key(), $subject);
    }

    public function testRequestWithUnreadableKeyThrows(): void
    {
        $this->expectException(Invalid::class);

        Certificate::request('not a key', ['commonName' => 'Example Distribution']);
    }

    public function testRequestRejectsKeyPath(): void
    {
        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('not a path');

        Certificate::request('file://' . __FILE__, ['commonName' => 'Example Distribution']);
    }

    public function testAccessors(): void
    {
        $key = Certificate::key();
        $certificate = $this->certificate($key, self::SUBJECT, 365);

        $this->assertSame(openssl_x509_fingerprint($certificate->pem, 'sha256'), $certificate->fingerprint());
        $this->assertSame(openssl_x509_fingerprint($certificate->pem, 'sha1'), $certificate->fingerprint('sha1'));
        $this->assertSame(hash('sha256', $certificate->der()), $certificate->fingerprint());
        $this->assertSame('ABCDE12345', $certificate->team());
        $this->assertSame(
            ['CN' => self::SUBJECT['commonName'], 'O' => 'Example Team', 'OU' => 'ABCDE12345', 'C' => 'US'],
            $certificate->subject(),
        );

        $parsed = openssl_x509_parse($certificate->pem);
        $this->assertIsArray($parsed);
        $this->assertSame($parsed['validTo_time_t'], $certificate->expiresAt()->getTimestamp());
        $this->assertEqualsWithDelta(time() + 365 * 86400, $certificate->expiresAt()->getTimestamp(), 3600);
    }

    public function testTeamIsNullWithoutOrganizationalUnit(): void
    {
        $certificate = $this->certificate(Certificate::key(), ['commonName' => 'Upload'], 1);

        $this->assertNull($certificate->team());
    }

    public function testUnsupportedFingerprintAlgorithmThrows(): void
    {
        $certificate = $this->certificate(Certificate::key(), ['commonName' => 'Upload'], 1);

        $this->expectException(Invalid::class);

        $certificate->fingerprint('unknown');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pems(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['not a certificate'];
        yield 'corrupt body' => ["-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n"];
        yield 'path' => ['file://' . __FILE__ . "\n-----BEGIN CERTIFICATE-----"];
    }

    #[DataProvider('pems')]
    public function testInvalidPemThrows(string $pem): void
    {
        $this->expectException(Invalid::class);

        new Certificate($pem);
    }

    public function testPkcs12RoundTrip(): void
    {
        $key = Certificate::key();
        $certificate = $this->certificate($key, self::SUBJECT, 365);

        $archive = $certificate->export($key, 'secret', 'Example Distribution');
        $read = Certificate::fromPkcs12($archive, 'secret');

        $this->assertSame($certificate->fingerprint(), $read->fingerprint());
        $this->assertSame('ABCDE12345', $read->team());

        $parts = [];
        $this->assertTrue(openssl_pkcs12_read($archive, $parts, 'secret'));
        $this->assertIsArray($parts);
        $this->assertIsString($parts['pkey']);
        $this->assertTrue(openssl_x509_check_private_key($read->pem, $parts['pkey']));
    }

    public function testPkcs12WrongPasswordThrows(): void
    {
        $key = Certificate::key();
        $archive = $this->certificate($key, self::SUBJECT, 1)->export($key, 'secret', 'Example');

        $this->expectException(Invalid::class);

        Certificate::fromPkcs12($archive, 'wrong');
    }

    public function testLegacyPkcs12AsksForReexport(): void
    {
        if (\in_array(Legacy::RC2, openssl_get_cipher_methods(), true)) {
            $this->markTestSkipped('The OpenSSL legacy provider is loaded, so legacy archives are readable');
        }

        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('legacy encryption');
        $this->expectExceptionMessage('-keypbe AES-256-CBC -certpbe AES-256-CBC -macalg sha256');

        Certificate::fromPkcs12(Legacy::pkcs12('secret'), 'secret');
    }

    public function testLegacyPkcs12WrongPasswordIsReportedAsPassword(): void
    {
        try {
            Certificate::fromPkcs12(Legacy::pkcs12('secret'), 'wrong');
            $this->fail('A wrong password must be rejected');
        } catch (Invalid $invalid) {
            $this->assertStringContainsString('password is wrong', $invalid->getMessage());
            $this->assertStringNotContainsString('legacy', $invalid->getMessage());
        }
    }

    public function testStaleOpenSslErrorsDoNotLeak(): void
    {
        $key = Certificate::key();
        $archive = $this->certificate($key, self::SUBJECT, 1)->export($key, 'secret', 'Example');
        $this->assertFalse(openssl_pkey_get_private('stale'));

        try {
            Certificate::fromPkcs12($archive, 'wrong');
            $this->fail('A wrong password must be rejected');
        } catch (Invalid $invalid) {
            $this->assertStringContainsString('password is wrong', $invalid->getMessage());
            $this->assertStringContainsString('mac verify failure', $invalid->getMessage());
        }

        $this->assertFalse(openssl_error_string());
    }

    public function testMalformedPkcs12Throws(): void
    {
        $this->expectException(Invalid::class);

        Certificate::fromPkcs12('not an archive', 'secret');
    }

    public function testExportWithMismatchedKeyThrows(): void
    {
        $certificate = $this->certificate(Certificate::key(), self::SUBJECT, 1);

        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('does not match');

        $certificate->export(Certificate::key(), 'secret', 'Example');
    }

    public function testExportWithoutNameThrows(): void
    {
        $key = Certificate::key();
        $certificate = $this->certificate($key, self::SUBJECT, 1);

        $this->expectException(Invalid::class);

        $certificate->export($key, 'secret', '');
    }

    /**
     * @param array<string, string> $subject
     */
    private function certificate(string $key, array $subject, int $days): Certificate
    {
        $options = ['digest_alg' => 'sha256', 'config' => Certificate::CONFIG];
        $signed = openssl_csr_sign(Certificate::request($key, $subject), null, $key, $days, $options, random_int(1, PHP_INT_MAX));
        $this->assertNotFalse($signed);

        $pem = null;
        $this->assertTrue(openssl_x509_export($signed, $pem));
        $this->assertIsString($pem);

        return new Certificate($pem);
    }

    /**
     * @return array<mixed>
     */
    private function details(string $key): array
    {
        $private = openssl_pkey_get_private($key);
        $this->assertNotFalse($private);

        $details = openssl_pkey_get_details($private);
        $this->assertIsArray($details);

        return $details;
    }
}
