<?php

namespace Utopia\Cdn\Certificates\Provider;

use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Domain;
use Utopia\Cdn\Exception\Configuration;

class Proxy implements Provider
{
    /** @param array<int, Provider> $customDomainProviders */
    public function __construct(
        private string $appDomain,
        private readonly Provider $appDomainProvider,
        private readonly Provider $networkProvider,
        private readonly array $customDomainProviders,
    ) {
        $this->appDomain = Domain::validate($this->appDomain);
    }

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $domain = Domain::validate($domain);
        $renewDate = null;

        foreach ($this->select($domain, $domainType) as $provider) {
            $candidate = $provider->issueCertificate($certName, $domain, $domainType);
            $renewDate = $candidate ?? $renewDate;
        }

        return $renewDate;
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        $domain = Domain::validate($domain);
        return array_all($this->select($domain, $domainType), fn(\Utopia\Cdn\Certificates\Provider $provider): bool => $provider->isInstantGeneration($domain, $domainType));
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        $domain = Domain::validate($domain);

        foreach ($this->select($domain, $domainType) as $provider) {
            if ($provider->isInstantGeneration($domain, $domainType)) {
                continue;
            }

            $status = $provider->getCertificateStatus($domain, $domainType);
            if ($status !== Status::ISSUED) {
                return $status;
            }
        }

        return Status::ISSUED;
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        $domain = Domain::validate($domain);
        return array_any($this->select($domain, $domainType), fn(\Utopia\Cdn\Certificates\Provider $provider): bool => $provider->isRenewRequired($domain, $domainType));
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $domain = Domain::validate($domain);

        foreach ($this->select($domain, $domainType) as $provider) {
            $provider->deleteCertificate($domain, $domainType);
        }
    }

    /**
     * @param string $domain Already normalized by the calling entry point.
     * @return array<int, Provider>
     */
    private function select(string $domain, ?string $domainType): array
    {
        if (\in_array($domainType, ['site', 'network', 'redirect'], true)) {
            return [$this->networkProvider];
        }

        if ($domain === $this->appDomain) {
            return [$this->appDomainProvider];
        }

        if ($this->customDomainProviders === []) {
            throw new Configuration('No certificate providers are configured for custom domains.');
        }

        return $this->customDomainProviders;
    }
}
