<?php

declare(strict_types=1);

namespace Tests\E2E\General\Certificates;

use Utopia\Cdn\Certificates\Provider as CertificateProvider;
use Utopia\Cdn\Certificates\Status;

final class Provider implements CertificateProvider
{
    /** @var array<array{string, ?string}> */
    public array $issued = [];
    /** @var array<array{string, ?string}> */
    public array $deleted = [];

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $this->issued[] = [$domain, $domainType];
        return null;
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        return false;
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        return true;
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        return Status::PENDING;
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $this->deleted[] = [$domain, $domainType];
    }
}
