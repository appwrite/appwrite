<?php

declare(strict_types=1);

namespace Utopia\Cdn\Certificates;

use Utopia\Cdn\Exception\Certificate;

interface Provider
{
    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string;

    public function isInstantGeneration(string $domain, ?string $domainType): bool;

    /**
     * @throws Certificate When issuance is blocked on the domain owner, or the provider has stopped trying.
     */
    public function getCertificateStatus(string $domain, ?string $domainType): string;

    /**
     * @return string|null When the certificate serving the domain expires, as `Y-m-d H:i:s.v` in UTC, or null when the provider cannot tell.
     */
    public function getCertificateExpiry(string $domain, ?string $domainType): ?string;

    public function isRenewRequired(string $domain, ?string $domainType): bool;

    public function deleteCertificate(string $domain, ?string $domainType = null): void;
}
