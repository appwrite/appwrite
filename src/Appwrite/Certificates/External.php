<?php

namespace Appwrite\Certificates;

use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;

/**
 * Certificates issued outside Appwrite, by a reverse proxy in front of it that
 * terminates TLS. A domain is verified by its DNS alone and nothing is issued,
 * renewed or deleted here.
 */
class External implements Provider
{
    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        return null;
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        return true;
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        return Status::ISSUED;
    }

    public function getCertificateExpiry(string $domain, ?string $domainType): ?string
    {
        return null;
    }

    /**
     * Always true, so the worker records an issue without a renew date and
     * maintenance never schedules this domain again.
     */
    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        return true;
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
    }
}
