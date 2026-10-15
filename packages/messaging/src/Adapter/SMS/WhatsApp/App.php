<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\SMS\WhatsApp;

/**
 * Android app allowed to receive the code through one-tap or zero-tap autofill.
 */
final readonly class App
{
    /**
     * Longest Android package name Meta accepts.
     */
    public const int PACKAGE_NAME_MAX_LENGTH = 224;

    /**
     * Exact length of the app signing certificate hash Meta expects.
     */
    public const int SIGNATURE_HASH_LENGTH = 11;

    /**
     * @param  string  $packageName Android application ID, for example `com.example.app`.
     * @param  string  $signatureHash Eleven-character hash of the app signing certificate, as shown by Meta's tooling.
     *
     * @throws \InvalidArgumentException If either value is outside what Meta accepts.
     */
    public function __construct(
        public string $packageName,
        public string $signatureHash,
    ) {
        if ($packageName === '' || \strlen($packageName) > self::PACKAGE_NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException('WhatsApp app package name must be between 1 and ' . self::PACKAGE_NAME_MAX_LENGTH . ' characters.');
        }

        if (\strlen($signatureHash) !== self::SIGNATURE_HASH_LENGTH) {
            throw new \InvalidArgumentException('WhatsApp app signature hash must be exactly ' . self::SIGNATURE_HASH_LENGTH . ' characters.');
        }
    }

    /**
     * @return array{package_name: string, signature_hash: string}
     */
    public function toArray(): array
    {
        return [
            'package_name' => $this->packageName,
            'signature_hash' => $this->signatureHash,
        ];
    }
}
