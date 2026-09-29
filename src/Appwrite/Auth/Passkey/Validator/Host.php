<?php

namespace Appwrite\Auth\Passkey\Validator;

use Utopia\Domains\Domain;
use Utopia\Validator;

/**
 * Relying party ID: a lowercase ASCII DNS name below a public suffix, or `localhost` for development.
 */
class Host extends Validator
{
    public const string LOCALHOST = 'localhost';

    public function getDescription(): string
    {
        return 'Value must be a lowercase domain name such as "example.com" or "localhost". IP addresses and public suffixes are not allowed.';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_STRING;
    }

    public function isValid($value): bool
    {
        if (!\is_string($value) || $value === '' || \strlen($value) > 253) {
            return false;
        }

        if ($value === self::LOCALHOST) {
            return true;
        }

        $labels = \explode('.', $value);
        if (\count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if ($label === '' || \strlen($label) > 63 || \str_starts_with($label, '-') || \str_ends_with($label, '-')) {
                return false;
            }
            if (!\ctype_alnum(\str_replace('-', '', $label)) || \strtolower($label) !== $label) {
                return false;
            }
        }

        // Rejects IPv4 addresses, whose last label is numeric
        if (\ctype_digit(\end($labels))) {
            return false;
        }

        $domain = new Domain($value);

        return $domain->isKnown() && $domain->getSuffix() !== $value;
    }
}
