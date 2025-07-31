<?php

namespace Appwrite\Domain\Validator;

use Utopia\System\System;
use Utopia\Validator\Domain as ValidatorDomain;

class AppwriteDomain extends ValidatorDomain
{
    public function getDescription(): string
    {
        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES');
        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS');
        return "Value must be a valid domain name. For Appwrite-managed domains, only one-level subdomain is allowed.";
    }

    public function isValid($value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        if ($value !== trim($value)) {
            return false;
        }

        $domain = strtolower($value);

        if (preg_match('/\\s/', $domain)) {
            return false;
        }

        if (strpos($domain, '_') !== false) {
            return false;
        }

        if (preg_match('/^[a-z]+:\/\//', $domain)) {
            $parsed = parse_url($domain);
            if ($parsed === false || !isset($parsed['host'])) {
                return false;
            }
            $domain = $parsed['host'];
        } elseif (strpos($domain, '/') !== false) {
            $parsed = parse_url('http://' . $domain);
            if ($parsed === false || !isset($parsed['host'])) {
                return false;
            }
            $domain = $parsed['host'];
        }

        if (!preg_match('/^[a-z0-9.-]+$/', $domain)) {
            return false;
        }

        if (str_starts_with($domain, '.') || str_ends_with($domain, '.') || strpos($domain, '..') !== false) {
            return false;
        }

        $parts = explode('.', $domain);
        if (count($parts) < 2 || empty($parts[0])) {
            return false;
        }

        foreach ($parts as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        $tld = end($parts);
        if (strlen($tld) < 2) {
            return false;
        }

        $firstLabel = $parts[0];
        $firstLabelLower = strtolower($firstLabel);
        if (str_starts_with($firstLabelLower, 'commit-') || str_starts_with($firstLabelLower, 'branch-')) {
            return false;
        }

        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS');
        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES');

        if ($domain === 'localhost' || $domain === 'api.localhost') {
            return false;
        }

        foreach ([$functionsDomain, $sitesDomain] as $managedDomain) {
            if (!empty($managedDomain)) {
                if ($domain === $managedDomain) {
                    return false;
                }

                if (str_ends_with($domain, '.' . $managedDomain)) {
                    $subdomain = substr($domain, 0, -strlen('.' . $managedDomain));

                    if (
                        $subdomain === '' ||
                        strpos($subdomain, '.') !== false ||
                        strlen($subdomain) > 63 ||
                        !preg_match('/^[a-z0-9-]+$/', $subdomain) ||
                        str_starts_with($subdomain, '-') ||
                        str_ends_with($subdomain, '-')
                    ) {
                        return false;
                    }
                    return true;
                }
            }
        }

        if (!parent::isValid($value)) {
            return false;
        }

        return true;
    }

    public function getType(): string
    {
        return 'string';
    }

    public function isArray(): bool
    {
        return false;
    }
}
