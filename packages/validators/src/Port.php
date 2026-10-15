<?php

declare(strict_types=1);

namespace Utopia\Validator;

/**
 * Port
 *
 * Validates a published port as Docker accepts it: a port (8080), or a port bound
 * to one host interface (127.0.0.1:8080, [::1]:8080).
 */
class Port extends Validator
{
    public function getDescription(): string
    {
        return 'Value must be a port between 1 and 65535, optionally prefixed by a host IP address (e.g. 127.0.0.1:80)';
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
        if (\is_int($value)) {
            $value = (string) $value;
        }

        if (!\is_string($value)) {
            return false;
        }

        $separator = \strrpos($value, ':');
        if ($separator === false) {
            return $this->isValidPort($value);
        }

        $host = \substr($value, 0, $separator);
        $port = \substr($value, $separator + 1);

        return $this->isValidHost($host) && $this->isValidPort($port);
    }

    private function isValidHost(string $host): bool
    {
        if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
            return \filter_var(\substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return \filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function isValidPort(string $port): bool
    {
        if (!\ctype_digit($port) || \str_starts_with($port, '0')) {
            return false;
        }

        $number = (int) $port;

        return $number >= 1 && $number <= 65535;
    }
}
