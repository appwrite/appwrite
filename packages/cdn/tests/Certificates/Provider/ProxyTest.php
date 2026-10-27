<?php

declare(strict_types=1);

namespace Utopia\Cdn\Tests\Certificates\Provider;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Provider\Proxy;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Exception\Configuration;

final class ProxyTest extends TestCase
{
    public function testRoutesAndAggregatesProviders(): void
    {
        $calls = new \ArrayObject();
        $app = $this->provider('app', Status::ISSUED, false, null, false, $calls);
        $network = $this->provider('network', Status::PENDING, false, null, true, $calls);
        $instant = $this->provider('cloudflare', Status::UNKNOWN, true, null, false, $calls);
        $fastly = $this->provider('fastly', Status::ISSUED, false, '2027-01-01', false, $calls);
        $proxy = new Proxy('app.example.com', $app, $network, [$instant, $fastly]);

        $this->assertSame('2027-01-01', $proxy->issueCertificate('cert', 'custom.example.com', null));
        $this->assertFalse($proxy->isInstantGeneration('custom.example.com', null));
        $this->assertSame(Status::ISSUED, $proxy->getCertificateStatus('custom.example.com', null));
        $this->assertTrue($proxy->isRenewRequired('site.example.com', 'site'));
        $proxy->deleteCertificate('app.example.com');
        $proxy->deleteCertificate('site.example.com', 'site');
        $this->assertContains('app:delete', $calls->getArrayCopy());
        $this->assertContains('network:delete', $calls->getArrayCopy());
    }

    public function testNormalizesDomainBeforeRoutingAndDelegating(): void
    {
        $seen = new \ArrayObject();
        $app = $this->recorder('app', $seen);
        $network = $this->recorder('network', $seen);
        $custom = $this->recorder('custom', $seen);
        $proxy = new Proxy('App.Example.COM', $app, $network, [$custom]);

        $proxy->deleteCertificate('APP.Example.CoM');

        // Routed to the app provider, which only happens if the mixed-case input
        // was folded before the appDomain comparison in select().
        $this->assertSame(['app:delete:app.example.com'], $seen->getArrayCopy());
    }

    public function testPassesNormalizedDomainToCustomProviders(): void
    {
        $seen = new \ArrayObject();
        $app = $this->recorder('app', $seen);
        $proxy = new Proxy('app.example.com', $app, $app, [$this->recorder('custom', $seen)]);

        $proxy->issueCertificate('cert', 'Custom.EXAMPLE.com', null);
        $proxy->getCertificateExpiry('Custom.EXAMPLE.com', null);

        $this->assertSame(['custom:issue:custom.example.com', 'custom:expiry:custom.example.com'], $seen->getArrayCopy());
    }

    public function testReportsTheEarliestExpiry(): void
    {
        $calls = new \ArrayObject();
        $app = $this->provider('app', Status::ISSUED, false, null, false, $calls, '2027-03-01 00:00:00.000');
        $unknown = $this->provider('cloudflare', Status::UNKNOWN, true, null, false, $calls);
        $later = $this->provider('fastly', Status::ISSUED, false, null, false, $calls, '2027-02-01 00:00:00.000');
        $sooner = $this->provider('cloudfront', Status::ISSUED, false, null, false, $calls, '2027-01-15 00:00:00.000');
        $proxy = new Proxy('app.example.com', $app, $app, [$unknown, $later, $sooner]);

        // A certificate about to lapse on one provider is not hidden by a newer one on another
        $this->assertSame('2027-01-15 00:00:00.000', $proxy->getCertificateExpiry('custom.example.com', null));
        $this->assertSame('2027-03-01 00:00:00.000', $proxy->getCertificateExpiry('app.example.com', null));
    }

    public function testExpiryIsUnknownWhenNoProviderCanTell(): void
    {
        $provider = $this->provider('cloudflare', Status::UNKNOWN, true, null, false, new \ArrayObject());
        $proxy = new Proxy('app.example.com', $provider, $provider, [$provider, $provider]);

        $this->assertNull($proxy->getCertificateExpiry('custom.example.com', null));
    }

    /** @param \ArrayObject<int, mixed> $seen */
    private function recorder(string $name, \ArrayObject $seen): Provider
    {
        return new readonly class ($name, $seen) implements Provider {
            /** @param \ArrayObject<int, mixed> $seen */
            public function __construct(private string $name, private \ArrayObject $seen)
            {
            }
            public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
            {
                $this->seen->append($this->name . ':issue:' . $domain);
                return null;
            }
            public function isInstantGeneration(string $domain, ?string $domainType): bool
            {
                return false;
            }
            public function getCertificateStatus(string $domain, ?string $domainType): string
            {
                return Status::ISSUED;
            }
            public function getCertificateExpiry(string $domain, ?string $domainType): ?string
            {
                $this->seen->append($this->name . ':expiry:' . $domain);
                return null;
            }
            public function isRenewRequired(string $domain, ?string $domainType): bool
            {
                return false;
            }
            public function deleteCertificate(string $domain, ?string $domainType = null): void
            {
                $this->seen->append($this->name . ':delete:' . $domain);
            }
        };
    }

    public function testRejectsMissingCustomProviders(): void
    {
        $provider = $this->provider('app', Status::ISSUED, false, null, false, new \ArrayObject());
        $proxy = new Proxy('app.example.com', $provider, $provider, []);
        $this->expectException(Configuration::class);
        $proxy->issueCertificate('cert', 'custom.example.com', null);
    }

    /** @param \ArrayObject<int, mixed> $calls */
    private function provider(string $name, string $status, bool $instant, ?string $date, bool $renew, \ArrayObject $calls, ?string $expiry = null): Provider
    {
        return new readonly class ($name, $status, $instant, $date, $renew, $calls, $expiry) implements Provider {
            /** @param \ArrayObject<int, mixed> $calls */
            public function __construct(private string $name, private string $status, private bool $instant, private ?string $date, private bool $renew, private \ArrayObject $calls, private ?string $expiry)
            {
            }
            public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
            {
                $this->calls->append($this->name . ':issue');
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
            public function getCertificateExpiry(string $domain, ?string $domainType): ?string
            {
                return $this->expiry;
            }
            public function isRenewRequired(string $domain, ?string $domainType): bool
            {
                return $this->renew;
            }
            public function deleteCertificate(string $domain, ?string $domainType = null): void
            {
                $this->calls->append($this->name . ':delete');
            }
        };
    }
}
