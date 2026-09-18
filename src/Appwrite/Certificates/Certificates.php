<?php

namespace Appwrite\Certificates;

use Utopia\Cdn\Certificates\Status;
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

    /**
     * Whether the provider already holds a usable certificate. A renewing certificate
     * is still live and serving, so it counts as issued rather than as one in flight.
     */
    public function isIssued(string $status): bool
    {
        return \in_array($status, [Status::ISSUED, Status::RENEWING], true);
    }

    /**
     * Whether the provider is still working on a first certificate for the domain.
     */
    public function isInFlight(string $status): bool
    {
        return \in_array($status, [Status::PENDING, Status::PROCESSING], true);
    }
}
