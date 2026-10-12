<?php

namespace Tests\Compat\Cdn;

use Utopia\Cdn\Cache\Adapter;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Exception\UnsupportedOperation;

/**
 * The cache adapter the PHP tests write inline (CacheTest, BalancerTest):
 * appends `name:operation` and the arguments to a shared log, cannot purge
 * keys unless told it can, and fails every purge when told to. Its twin is
 * `CacheRecorder` in crates/tools/compat/src/libs/cdn.rs.
 */
final readonly class CacheRecorder implements Adapter
{
    /** @param \ArrayObject<int, mixed> $log */
    public function __construct(
        private string $name,
        private \ArrayObject $log,
        private bool $supportsKeys = true,
        private bool $fails = false,
    ) {
    }

    public function purgePaths(string $domain, array $paths): void
    {
        $this->record('paths', [$domain, $paths]);
    }

    public function purgeDomain(string $domain): void
    {
        $this->record('domain', [$domain]);
    }

    public function purgeKeys(array $keys): void
    {
        if (!$this->supportsKeys) {
            throw new UnsupportedOperation($this->name . ' cannot purge keys.');
        }

        $this->record('keys', [$keys]);
    }

    public function purgeZone(): void
    {
        $this->record('zone', []);
    }

    /** @param list<mixed> $args */
    private function record(string $operation, array $args): void
    {
        $this->log->append(['call' => $this->name . ':' . $operation, 'args' => $args]);

        if ($this->fails) {
            throw new \RuntimeException($this->name . ' purge failed.');
        }
    }
}

/**
 * The certificate provider the PHP tests write inline (CertificatesTest,
 * ProxyTest): answers with fixed values and appends
 * `name:operation:domain` to a shared log. Its twin is `ProviderRecorder` in
 * crates/tools/compat/src/libs/cdn.rs.
 */
final readonly class ProviderRecorder implements Provider
{
    /** @param \ArrayObject<int, mixed> $log */
    public function __construct(
        private string $name,
        private \ArrayObject $log,
        private string $status,
        private bool $instant,
        private ?string $date,
        private bool $renew,
    ) {
    }

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $this->log->append($this->name . ':issue:' . $domain);

        return $this->date;
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        return $this->instant;
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        return $this->status;
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        return $this->renew;
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $this->log->append($this->name . ':delete:' . $domain);
    }
}
