<?php

namespace Appwrite\Network\Validator;

use Utopia\Domains\Domain;
use Utopia\Validator\URL;

/**
 * An http(s) URL whose host is a known public domain (or an IP literal) that Appwrite may
 * connect to on a user's behalf. Used on endpoints that fetch user-controlled URLs.
 */
class PublicURL extends URL
{
    private string $reason = '';

    public function __construct(
        private readonly PublicHostname $hostname,
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

        $host = \parse_url($value, PHP_URL_HOST) ?? '';

        if (\filter_var(\trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            try {
                $known = (new Domain($host))->isKnown();
            } catch (\Throwable) {
                $known = false;
            }

            if (!$known) {
                $this->reason = "Hostname '{$host}' is not a known public domain.";
                return false;
            }
        }

        if (!$this->hostname->isValid($host)) {
            $this->reason = $this->hostname->getDescription();
            return false;
        }

        return true;
    }
}
