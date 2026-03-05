<?php

namespace Appwrite\Platform\Installer\Validator;

use Utopia\Validator;

/**
 * AppDomain
 *
 * Validates an app domain input: hostname, IP, localhost,
 * or IPv6 bracket notation with optional port (e.g. [::1]:8080).
 */
class AppDomain extends Validator
{
    private const string PATTERN_IPV6_WITH_PORT = '/^\[(.+)](?::(\d+))?$/';

    public function getDescription(): string
    {
        return 'Value must be a valid hostname, IP address, or bracket-notation IPv6 address with optional port';
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
        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $host = $value;
        $port = null;

        if (str_starts_with($value, '[')) {
            if (!preg_match(self::PATTERN_IPV6_WITH_PORT, $value, $matches)) {
                return false;
            }
            $host = $matches[1] ?? '';
            $port = $matches[2] ?? null;
        } else {
            $parts = explode(':', $value);
            if (count($parts) > 2) {
                return false;
            }
            if (count($parts) === 2) {
                [$host, $port] = $parts;
            }
        }

        if ($port !== null && $port !== '') {
            $portInt = (int) $port;
            if ((string) $portInt !== $port || $portInt < 1 || $portInt > 65535) {
                return false;
            }
        }

        return $this->isValidDomain($host);
    }

    private function isValidDomain(string $value): bool
    {
        if ($value === 'localhost') {
            return true;
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
