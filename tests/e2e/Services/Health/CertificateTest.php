<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Health;

final class CertificateTest extends HealthBase
{
    public function testCertificateValidity(): void
    {
        $this->assertCertificate('www.google.com', '/CN=www.google.com', 'www.google.com');
        $this->assertCertificate('appwrite.io', '/CN=appwrite.io', 'appwrite.io');

        /**
         * Wrapped in assertEventually to handle transient external URL failures
         */
        $this->assertEventually(function () {
            $response = $this->callGet('/health/certificate', ['domain' => 'https://google.com']);
            $this->assertEquals(200, $response['headers']['status-code']);
        }, 30_000, 2_000);

        $this->assertCertificateFailure('localhost', 400);
        $this->assertCertificateFailure('doesnotexist.com', 404);
        $this->assertCertificateFailure('www.google.com/usr/src/local', 400);
        $this->assertCertificateFailure('', 400);
    }

    /**
     * Wrapped in assertEventually to handle transient external URL failures
     */
    private function assertCertificate(string $domain, string $expectedName, string $expectedSN): void
    {
        $this->assertEventually(function () use ($domain, $expectedName, $expectedSN) {
            $response = $this->callGet('/health/certificate', ['domain' => $domain]);
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($expectedName, $response['body']['name']);
            $this->assertEquals($expectedSN, $response['body']['subjectSN']);
            $this->assertContains($response['body']['issuerOrganisation'], ["Let's Encrypt", 'Google Trust Services']);
            $this->assertIsInt($response['body']['validFrom']);
            $this->assertIsInt($response['body']['validTo']);
        }, 30_000, 2_000);
    }

    private function assertCertificateFailure(string $domain, int $status): void
    {
        $response = $this->callGet('/health/certificate', ['domain' => $domain]);
        $this->assertEquals($status, $response['headers']['status-code']);
    }
}
