<?php

namespace Appwrite\Certificates;

use Utopia\Logger\Log;

interface Adapter
{
    public function isIssueInstant(string $domain, ?string $domainType): bool;

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string;

    public function isRenewRequired(string $domain, ?string $domainType, Log $log): bool;

    public function deleteCertificate(string $domain): void;

    public function getIssueStatus(string $domain, ?string $domainType): ?string;
}
