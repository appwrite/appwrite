<?php

namespace Appwrite\Certificates;

use Utopia\Logger\Log;

interface Adapter
{
    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string;

    public function isRenewRequired(string $domain, ?string $domainType, Log $log): bool;

    public function deleteCertificate(string $domain): void;
}
