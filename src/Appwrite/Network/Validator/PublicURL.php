<?php

namespace Appwrite\Network\Validator;

use Appwrite\Network\Allowlist;
use Utopia\Domains\Domain;
use Utopia\Validator\URL;

/**
 * Validates an http(s) URL whose host is a known public domain (or a public
 * IP literal) that resolves only to publicly routable addresses. Used on
 * endpoints that fetch user-controlled URLs.
 */
class PublicURL extends URL
{
    private string $reason = '';

    public function __construct(
        private readonly Allowlist $allowlist = new Allowlist(),
    ) {
        parent::__construct(['http', 'https']);
    }

    public function getDescription(): string
    {
        return $this->reason !== '' ? $this->reason : parent::getDescription();
    }

    public function isValid($value): bool
    {
        $this->reason = '';

        if (!parent::isValid($value)) {
            return false;
        }

        if (\str_contains($value, '\\') || \parse_url($value, PHP_URL_USER) !== null) {
            $this->reason = 'URL must not contain credentials or backslashes.';
            return false;
        }

        $host = \parse_url($value, PHP_URL_HOST) ?? '';

        if (\filter_var(\trim($host, '[]'), FILTER_VALIDATE_IP) === false && !$this->isKnown($host) && !$this->isAllowed($host)) {
            $this->reason = "Hostname '{$host}' is not a known public domain.";
            return false;
        }

        $hostname = new PublicHostname($this->allowlist);
        if (!$hostname->isValid($host)) {
            $this->reason = $hostname->getDescription();
            return false;
        }

        return true;
    }

    private function isKnown(string $host): bool
    {
        try {
            return (new Domain($host))->isKnown();
        } catch (\Throwable) {
            return false;
        }
    }

    private function isAllowed(string $host): bool
    {
        if (PublicHostname::isNumericAddress($host)) {
            return false;
        }

        if ($this->allowlist->hasHostname($host)) {
            return true;
        }

        if (!$this->allowlist->hasSubnets()) {
            return false;
        }

        return $this->allowlist->admits(PublicHostname::resolve($host));
    }
}
