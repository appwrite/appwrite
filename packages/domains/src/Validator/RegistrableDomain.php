<?php

namespace Utopia\Domains\Validator;

use Utopia\Domains\Domain;
use Utopia\Validator\Domain as DomainName;
use Utopia\Validator\Validator;

/**
 * Validate that a value is a lowercase domain name under a known public suffix, such as `example.com`
 * or `app.example.com`. Public suffixes themselves (`com`, `co.uk`, `github.io`), IP addresses,
 * URLs and single-label names like `localhost` are rejected.
 */
class RegistrableDomain extends Validator
{
    public function getDescription(): string
    {
        return 'Value must be a lowercase domain name such as "example.com". IP addresses and public suffixes are not allowed.';
    }

    public function isValid($value): bool
    {
        if (!\is_string($value) || $value !== \strtolower($value)) {
            return false;
        }

        if (!new DomainName()->isValid($value) || \filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $domain = new Domain($value);

        return $domain->isKnown() && $domain->getSuffix() !== $value;
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
