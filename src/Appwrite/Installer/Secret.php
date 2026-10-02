<?php

namespace Appwrite\Installer;

/**
 * One-time secret that authorizes requests to the web installer.
 *
 * The installer server is started in the background with its output sent to a
 * log file, so the launching process issues the secret and hands it over
 * through the environment; only that process can show it to the operator.
 */
final readonly class Secret
{
    public const string ENVIRONMENT = 'APPWRITE_INSTALLER_SECRET';
    public const string HEADER = 'x-appwrite-installer-secret';

    public function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        return new self(\bin2hex(\random_bytes(32)));
    }

    public static function fromEnvironment(): self
    {
        $value = \getenv(self::ENVIRONMENT);

        return \is_string($value) && $value !== '' ? new self($value) : self::generate();
    }

    public function matches(string $candidate): bool
    {
        return $this->value !== '' && $candidate !== '' && \hash_equals($this->value, $candidate);
    }
}
