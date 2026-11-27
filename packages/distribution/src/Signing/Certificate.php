<?php

declare(strict_types=1);

namespace Utopia\Distribution\Signing;

use Utopia\Distribution\Exception\Invalid;

final readonly class Certificate
{
    public const string CONFIG = __DIR__ . '/../../data/openssl.cnf';

    private const string DIGEST = 'sha256';

    private const int BITS = 2048;

    private const string PEM_BEGIN = '-----BEGIN CERTIFICATE-----';

    private const string PEM_END = '-----END CERTIFICATE-----';

    private const string PATH = 'file://';

    private const string UNSUPPORTED = 'unsupported';

    private const string UNKNOWN_CIPHER = 'unknown cipher';

    private const string LEGACY = 'PKCS#12 archive uses legacy encryption (such as RC2-40) that this OpenSSL cannot read: re-export it with AES-256, for example `openssl pkcs12 -export -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg sha256`';

    private \DateTimeImmutable $expiry;

    /**
     * @var array<string, string>
     */
    private array $fields;

    public function __construct(public string $pem)
    {
        if (str_starts_with($pem, self::PATH) || !str_contains($pem, self::PEM_BEGIN)) {
            throw new Invalid('Certificate is not a PEM certificate');
        }

        $parsed = openssl_x509_parse($pem);
        if ($parsed === false || !\is_int($parsed['validTo_time_t'] ?? null) || !\is_array($parsed['subject'] ?? null)) {
            throw self::failure('Certificate could not be parsed');
        }

        $this->expiry = new \DateTimeImmutable('@' . $parsed['validTo_time_t']);

        $fields = [];
        foreach ($parsed['subject'] as $field => $value) {
            $fields[(string) $field] = \is_string($value) ? $value : implode(', ', array_filter((array) $value, \is_string(...)));
        }

        $this->fields = $fields;
    }

    public static function fromPkcs12(string $archive, #[\SensitiveParameter] string $password): self
    {
        self::drain();

        $parts = [];
        if (!openssl_pkcs12_read($archive, $parts, $password) || !\is_array($parts)) {
            $errors = self::drain();
            foreach ($errors as $error) {
                if (str_contains($error, self::UNSUPPORTED) || str_contains($error, self::UNKNOWN_CIPHER)) {
                    throw new Invalid(self::LEGACY);
                }
            }

            throw self::failure('PKCS#12 archive could not be read: it is malformed or the password is wrong', $errors);
        }

        $certificate = $parts['cert'] ?? null;
        $key = $parts['pkey'] ?? null;
        if (!\is_string($certificate) || !\is_string($key)) {
            throw new Invalid('PKCS#12 archive must hold a certificate and its private key');
        }

        if (!openssl_x509_check_private_key($certificate, $key)) {
            throw self::failure('PKCS#12 private key does not match its certificate');
        }

        return new self($certificate);
    }

    public static function key(): string
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => self::BITS,
            'config' => self::CONFIG,
        ]);

        $pem = null;
        if ($key === false || !openssl_pkey_export($key, $pem, null, ['config' => self::CONFIG]) || !\is_string($pem)) {
            throw self::failure('Private key could not be generated');
        }

        return $pem;
    }

    /**
     * @param array<string, string> $subject
     */
    public static function request(#[\SensitiveParameter] string $key, array $subject): string
    {
        if ($subject === []) {
            throw new Invalid('Certificate request subject must not be empty');
        }

        $privateKey = self::privateKey($key);

        set_error_handler(static fn (int $severity, string $message): never => throw new Invalid("Certificate request subject is invalid: {$message}"));
        try {
            $request = openssl_csr_new($subject, $privateKey, ['digest_alg' => self::DIGEST, 'config' => self::CONFIG]);
        } finally {
            restore_error_handler();
        }

        $pem = null;
        if (!$request instanceof \OpenSSLCertificateSigningRequest || !openssl_csr_export($request, $pem) || !\is_string($pem)) {
            throw self::failure('Certificate request could not be created');
        }

        return $pem;
    }

    public function export(#[\SensitiveParameter] string $key, #[\SensitiveParameter] string $password, string $name): string
    {
        if ($name === '') {
            throw new Invalid('PKCS#12 friendly name must not be empty');
        }

        $privateKey = self::privateKey($key);

        if (!openssl_x509_check_private_key($this->pem, $privateKey)) {
            throw self::failure('Private key does not match the certificate');
        }

        $archive = null;
        if (!openssl_pkcs12_export($this->pem, $archive, $privateKey, $password, ['friendly_name' => $name]) || !\is_string($archive)) {
            throw self::failure('PKCS#12 archive could not be created');
        }

        return $archive;
    }

    public function fingerprint(string $algorithm = self::DIGEST): string
    {
        if (!\in_array($algorithm, openssl_get_md_methods(true), true)) {
            throw new Invalid("Fingerprint algorithm \"{$algorithm}\" is not supported");
        }

        $fingerprint = openssl_x509_fingerprint($this->pem, $algorithm);
        if ($fingerprint === false) {
            throw self::failure('Certificate fingerprint could not be computed');
        }

        return $fingerprint;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiry;
    }

    /**
     * @return array<string, string>
     */
    public function subject(): array
    {
        return $this->fields;
    }

    public function team(): ?string
    {
        return $this->fields['OU'] ?? null;
    }

    public function der(): string
    {
        $pem = null;
        if (!openssl_x509_export($this->pem, $pem) || !\is_string($pem)) {
            throw self::failure('Certificate could not be exported');
        }

        $der = base64_decode(str_replace([self::PEM_BEGIN, self::PEM_END, "\r", "\n"], '', $pem), true);
        if ($der === false) {
            throw new Invalid('Certificate could not be decoded');
        }

        return $der;
    }

    private static function privateKey(#[\SensitiveParameter] string $key): \OpenSSLAsymmetricKey
    {
        if (str_starts_with($key, self::PATH)) {
            throw new Invalid('Private key must be PEM text, not a path');
        }

        $privateKey = openssl_pkey_get_private($key);
        if ($privateKey === false) {
            throw self::failure('Private key could not be read');
        }

        return $privateKey;
    }

    /**
     * @param list<string>|null $errors
     */
    private static function failure(string $message, ?array $errors = null): Invalid
    {
        $last = array_last($errors ?? self::drain());

        return new Invalid($last === null ? $message : "{$message} ({$last})");
    }

    /**
     * @return list<string>
     */
    private static function drain(): array
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors;
    }
}
