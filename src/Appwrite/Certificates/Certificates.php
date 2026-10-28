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

    /**
     * Why maintenance must not queue a renewal for a due certificate row, or null when it should. The worker acts only
     * on a verifying or verified rule and renews only the row the rule points to, so a job for any other row changes
     * nothing and the row stays due.
     *
     * - orphaned: no rule has the row's domain.
     * - region: the rule belongs to another region, whose maintenance renews it.
     * - superseded: the rule points to another certificate row.
     * - failed: the rule is not verified, and waits for a manual retry.
     * - disabled: Appwrite no longer auto-issues the rule's subdomain.
     */
    public function getRenewalSkipReason(Document $certificate, Document $rule, string $region): ?string
    {
        if ($rule->isEmpty()) {
            return 'orphaned';
        }

        if ($rule->getAttribute('region') !== $region) {
            return 'region';
        }

        if (!\in_array($rule->getAttribute('certificateId', ''), ['', $certificate->getId()], true)) {
            return 'superseded';
        }

        if (!\in_array($rule->getAttribute('status'), [RULE_STATUS_CERTIFICATE_GENERATING, RULE_STATUS_VERIFIED], true)) {
            return 'failed';
        }

        // If Appwrite would not auto-issue this subdomain today, it must not auto-renew it either. The owner gate
        // keeps custom-domain renewals from ever being skipped.
        if ($rule->getAttribute('owner') === 'Appwrite' && !$this->isAutoIssueEnabled($rule)) {
            return 'disabled';
        }

        return null;
    }
}
