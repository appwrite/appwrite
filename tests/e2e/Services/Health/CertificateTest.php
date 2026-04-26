<?php

namespace Tests\E2E\Services\Health;

class CertificateTest extends HealthBase
{
    public function testCertificateValidity(): void
    {
        $this->assertCertificate('www.google.com', '/CN=www.google.com', 'www.google.com');
        $this->assertCertificate('appwrite.io', '/CN=appwrite.io', 'appwrite.io');

        $response = $this->callGet('/health/certificate', ['domain' => 'https://google.com']);
        $this->assertEquals(200, $response['headers']['status-code']);

        $this->assertCertificateFailure('localhost', 400);
        $this->assertCertificateFailure('doesnotexist.com', 404);
        $this->assertCertificateFailure('www.google.com/usr/src/local', 400);
        $this->assertCertificateFailure('', 400);
    }

    private function assertCertificate(string $domain, string $expectedName, string $expectedSN): void
    {
        $response = $this->callGet('/health/certificate', ['domain' => $domain]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($expectedName, $response['body']['name']);
        $this->assertEquals($expectedSN, $response['body']['subjectSN']);
        $this->assertContains($response['body']['issuerOrganisation'], ["Let's Encrypt", 'Google Trust Services']);
        $this->assertIsInt($response['body']['validFrom']);
        $this->assertIsInt($response['body']['validTo']);
    }

    private function assertCertificateFailure(string $domain, int $status): void
    {
        $response = $this->callGet('/health/certificate', ['domain' => $domain]);
        $this->assertEquals($status, $response['headers']['status-code']);
    }
}
