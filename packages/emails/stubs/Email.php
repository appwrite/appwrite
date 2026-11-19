<?php

// Utopia\Emails\Email is registered by the utopia PHP extension (crates/extension in
// appwrite/appwrite). This file only declares it for static analysis and editors; it is never loaded.

namespace Utopia\Emails;

use Utopia\Domains\Domain;

/**
 * An email address: its local part and domain, its classification (free, disposable,
 * corporate) and its canonical form.
 */
class Email
{
    public const LOCAL_MAX_LENGTH = 64;

    public const DOMAIN_MAX_LENGTH = 253;

    public const FORMAT_FULL = 'full';

    public const FORMAT_LOCAL = 'local';

    public const FORMAT_DOMAIN = 'domain';

    public const FORMAT_PROVIDER = 'provider';

    public const FORMAT_SUBDOMAIN = 'subdomain';

    protected string $email = '';

    protected string $local = '';

    protected string $domain = '';

    /**
     * @var string[]
     */
    protected array $parts = [];

    protected ?Domain $domainInstance = null;

    /**
     * @throws \Exception when the address is empty or is not one local part and one domain
     */
    public function __construct(string $email)
    {
    }

    public function get(): string
    {
    }

    public function getLocal(): string
    {
    }

    public function getDomain(): string
    {
    }

    public function isValid(): bool
    {
    }

    public function hasValidLocal(): bool
    {
    }

    public function hasValidDomain(): bool
    {
    }

    public function isDisposable(): bool
    {
    }

    public function isFree(): bool
    {
    }

    public function isCorporate(): bool
    {
    }

    public function getProvider(): string
    {
    }

    public function getSubdomain(): string
    {
    }

    public function hasSubdomain(): bool
    {
    }

    public function getCanonical(): string
    {
    }

    public function isCanonicalSupported(): bool
    {
    }

    public function getCanonicalDomain(): ?string
    {
    }

    public function getFormatted(string $format = self::FORMAT_FULL): string
    {
    }
}
