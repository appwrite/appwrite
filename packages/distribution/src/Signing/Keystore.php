<?php

declare(strict_types=1);

namespace Utopia\Distribution\Signing;

use Utopia\Distribution\Exception\Invalid;

final readonly class Keystore
{
    public const string JKS = "\xFE\xED\xFE\xED";

    public const string JCEKS = "\xCE\xCE\xCE\xCE";

    public const int YEARS = 30;

    private const string DIGEST = 'sha256';

    private const int PASSWORD_BYTES = 24;

    private const string REDACTED = '[redacted]';

    public function __construct(
        public string $archive,
        #[\SensitiveParameter]
        public string $password,
        public string $alias,
        public Certificate $certificate,
    ) {
        if ($archive === '') {
            throw new Invalid('Keystore archive must not be empty');
        }

        if ($alias === '') {
            throw new Invalid('Keystore alias must not be empty');
        }
    }

    /**
     * @return array<string, string|Certificate>
     */
    public function __debugInfo(): array
    {
        return ['archive' => $this->archive, 'password' => self::REDACTED, 'alias' => $this->alias, 'certificate' => $this->certificate];
    }

    /**
     * @param array<string, string> $subject
     */
    public static function generate(string $alias, array $subject, int $years = self::YEARS): self
    {
        if ($years < 1) {
            throw new Invalid('Keystore validity must be at least one year');
        }

        $key = Certificate::key();
        $request = Certificate::request($key, $subject);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $days = $now->diff($now->modify("+{$years} years"))->days;

        self::drain();

        $signed = openssl_csr_sign($request, null, $key, $days, ['digest_alg' => self::DIGEST, 'config' => Certificate::CONFIG], random_int(1, PHP_INT_MAX));

        $pem = null;
        if ($signed === false || !openssl_x509_export($signed, $pem) || !\is_string($pem)) {
            throw new Invalid('Keystore certificate could not be signed: ' . (self::drain() ?? 'unknown OpenSSL error'));
        }

        $certificate = new Certificate($pem);
        $password = bin2hex(random_bytes(self::PASSWORD_BYTES));

        return new self($certificate->export($key, $password, $alias), $password, $alias, $certificate);
    }

    public static function fromPkcs12(string $archive, #[\SensitiveParameter] string $password, string $alias): self
    {
        if (self::isJks($archive) || self::isJceks($archive)) {
            throw new Invalid('Keystore is a JKS or JCEKS file, which cannot be read: convert it to PKCS#12 with `keytool -importkeystore -deststoretype PKCS12`');
        }

        return new self($archive, $password, $alias, Certificate::fromPkcs12($archive, $password));
    }

    public static function isJks(string $archive): bool
    {
        return str_starts_with($archive, self::JKS);
    }

    public static function isJceks(string $archive): bool
    {
        return str_starts_with($archive, self::JCEKS);
    }

    private static function drain(): ?string
    {
        $last = null;
        while (($error = openssl_error_string()) !== false) {
            $last = $error;
        }

        return $last;
    }
}
