<?php

declare(strict_types=1);

namespace Utopia\Cdn;

use Utopia\Cdn\Certificates\Provider;

class Certificates
{
    public function __construct(private readonly Provider $provider) {}

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        return $this->provider->issueCertificate($certName, $domain, $domainType);
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        return $this->provider->isInstantGeneration($domain, $domainType);
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        return $this->provider->getCertificateStatus($domain, $domainType);
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        return $this->provider->isRenewRequired($domain, $domainType);
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $this->provider->deleteCertificate($domain, $domainType);
    }
}
