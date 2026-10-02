<?php

namespace Appwrite\Installer;

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
