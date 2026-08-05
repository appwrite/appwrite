<?php

namespace Appwrite\Certificates;

use Utopia\Database\Document;
use Utopia\Domains\Domain;
use Utopia\System\System;

final class Certificates
{
    public function __construct(private Document $rule)
    {
    }

    /**
     * Whether Appwrite should auto-issue a per-subdomain TLS certificate for an
     * Appwrite-owned function or site primary domain. Skipped when a wildcard
     * certificate already covers the parent domain, or when the domain is not a
     * known public hostname.
     */
    public function isAutoIssueEnabled(): bool
    {
        if ($this->rule->getAttribute('owner', '') !== 'Appwrite') {
            return false;
        }

        if (System::getEnv('_APP_EDITION', 'self-hosted') !== 'self-hosted') {
            return false;
        }

        if (System::getEnv('_APP_ROUTER_AUTO_CERTIFICATES', 'enabled') === 'disabled') {
            return false;
        }

        $domain = new Domain($this->rule->getAttribute('domain', ''));

        return $domain->isKnown() && !$domain->isTest();
    }
}
