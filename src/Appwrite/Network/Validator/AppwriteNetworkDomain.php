<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator;

class AppwriteNetworkDomain extends Validator
{
    public function getDescription(): string
    {
        return 'Sub-subdomains are not allowed for appwrite.network. Only one level of subdomain is permitted.';
    }

    public function isValid($value): bool
    {
        if (!is_string($value) || empty($value)) {
            return true;
        }
        if (\str_starts_with($value, '.')) {
            return false;
        }
        if (\str_ends_with($value, '.appwrite.network.')) {
            return false;
        }
        if (!\str_ends_with(\strtolower($value), '.appwrite.network')) {
            return true;
        }
        $subdomain = substr(strtolower($value), 0, -strlen('.appwrite.network'));
        if (\str_contains($subdomain, '.')) {
            return false;
        }
        return true;
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