<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures;

use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;

final class CertificateProvider implements Provider
{
    public bool $instant = false;
    public bool $renew = true;
    public string $status = Status::PENDING;
    public ?\Closure $onIssue = null;
    public ?\Closure $onRenew = null;
    /** @var array<array{string, ?string}> */
    public array $issued = [];
    /** @var array<array{string, ?string}> */
    public array $deleted = [];

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $this->issued[] = [$domain, $domainType];
        ($this->onIssue ?? static fn () => null)();
        return null;
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        return $this->instant;
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        ($this->onRenew ?? static fn () => null)();
        return $this->renew;
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        return $this->status;
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $this->deleted[] = [$domain, $domainType];
    }
}
