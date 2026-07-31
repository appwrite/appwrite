<?php

namespace Appwrite\Certificates;

use Utopia\Domains\Domain;
use Utopia\System\System;

final class Issuance
{
    /**
     * Whether Appwrite should auto-issue a per-subdomain TLS certificate for an
     * Appwrite-owned function or site primary domain. Skipped when a wildcard
     * certificate already covers the parent domain, or when the domain is not a
     * known public hostname.
     */
    public static function isRequired(string $domain): bool
    {
        if (System::getEnv('_APP_EDITION', 'self-hosted') !== 'self-hosted') {
            return false;
        }

        if (System::getEnv('_APP_OPTIONS_ROUTER_CERTIFICATES', 'enabled') === 'disabled') {
            return false;
        }

        $domain = new Domain($domain);

        return $domain->isKnown() && !$domain->isTest();
    }
}
