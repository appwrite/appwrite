<?php

namespace Appwrite\Certificates;

use Utopia\Database\Document;
use Utopia\Domains\Domain;

final class Certificates
{
    public function __construct(
        private string $edition = 'self-hosted',
        private string $autoCertificates = 'enabled',
    ) {
    }

    /**
     * Whether Appwrite should auto-issue a per-subdomain TLS certificate for an
     * Appwrite-owned function or site primary domain. Skipped when a wildcard
     * certificate already covers the parent domain, or when the domain is not a
     * known public hostname.
     */
    public function isAutoIssueEnabled(Document $rule): bool
    {
        if ($rule->getAttribute('owner', '') !== 'Appwrite') {
            return false;
        }

        if ($this->edition !== 'self-hosted') {
            return false;
        }

        if ($this->autoCertificates === 'disabled') {
            return false;
        }

        $domain = new Domain($rule->getAttribute('domain', ''));

        return $domain->isKnown() && !$domain->isTest();
    }
}
