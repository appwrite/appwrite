<?php

declare(strict_types=1);

namespace Utopia\Cdn\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;

final class CertificatesTest extends TestCase
{
    public function testIssueCertificateDelegatesToProvider(): void
    {
        $certificates = new Certificates(new class () implements Provider {
            public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
            {
                if ($domainType === 'pending') {
                    return null;
                }

                return '2027-01-01 00:00:00.000';
            }

            public function isInstantGeneration(string $domain, ?string $domainType): bool
            {
                return false;
            }

            public function getCertificateStatus(string $domain, ?string $domainType): string
            {
                return Status::UNKNOWN;
            }

            public function getCertificateExpiry(string $domain, ?string $domainType): ?string
            {
                return $domainType === 'pending' ? null : '2027-01-31 00:00:00.000';
            }

            public function isRenewRequired(string $domain, ?string $domainType): bool
            {
                return false;
            }

            public function deleteCertificate(string $domain, ?string $domainType = null): void
            {
            }
        });

        $this->assertSame(
            '2027-01-01 00:00:00.000',
            $certificates->issueCertificate('cert-name', 'cdn.example.com', null),
        );
        $this->assertSame('2027-01-31 00:00:00.000', $certificates->getCertificateExpiry('cdn.example.com', null));
    }
}
