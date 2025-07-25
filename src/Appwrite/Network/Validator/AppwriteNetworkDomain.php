<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator;

class AppwriteNetworkDomain extends Validator
{
    public function getDescription(): string
    {
        $suffix = getenv('_APP_DOMAIN_SITES') ?: '.appwrite.network';
        return "Sub-subdomains are not allowed for {$suffix}. Only one level of subdomain is permitted.";
    }

    public function isValid($value): bool
    {
        $suffix = getenv('_APP_DOMAIN_SITES') ?: '.appwrite.network';

        if (!is_string($value) || empty($value)) {
            return true;
        }
        if (\str_starts_with($value, '.')) {
            return false;
        }
        if (\str_ends_with($value, $suffix . '.')) {
            return false;
        }
        if (!\str_ends_with(\strtolower($value), $suffix)) {
            return true;
        }
        $subdomain = \str_replace($suffix, '', \strtolower($value));
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