<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator\Hostname;
use Utopia\Validator\Validator;

/**
 * A server to connect to: a hostname, an IPv4 address, or an IPv6 address, written bare
 * (`2001:db8::1`) or in the square brackets URLs put around it (`[2001:db8::1]`).
 */
class HostnameOrIP extends Validator
{
    public function getDescription(): string
    {
        return 'Value must be a valid hostname, IPv4 address or IPv6 address.';
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
        if (!\is_string($value)) {
            return false;
        }

        $host = self::normalize($value);

        if (\filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return true;
        }

        // Brackets only ever surround an IPv6 address, and no hostname holds whitespace.
        return \strpbrk($host, "[] \t\n\r") === false && (new Hostname())->isValid($host);
    }

    /**
     * The host as it is stored: trimmed, and an IPv6 address without its brackets.
     */
    public static function normalize(string $host): string
    {
        $host = \trim($host);

        if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
            $address = \substr($host, 1, -1);

            if (\filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                return $address;
            }
        }

        return $host;
    }
}
