<?php

namespace Appwrite\Certificates;

interface Adapter
{
    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string;

    public function isInstantGeneration(string $domain, ?string $domainType): bool;

    public function getCertificateStatus(string $domain, ?string $domainType): string;

    public function isRenewRequired(string $domain, ?string $domainType): bool;

    public function deleteCertificate(string $domain): void;
}
