<?php

declare(strict_types=1);

namespace Tests\Unit\Certificates;

use Appwrite\Certificates\LetsEncrypt;
use PHPUnit\Framework\TestCase;

final class LetsEncryptTest extends TestCase
{
    private string $domain;

    protected function setUp(): void
    {
        if (!\is_dir(APP_STORAGE_CERTIFICATES) || !\is_writable(APP_STORAGE_CERTIFICATES)) {
            $this->markTestSkipped(APP_STORAGE_CERTIFICATES . ' is not writable.');
        }

        $this->domain = \uniqid() . '.letsencrypt.test';
    }

    protected function tearDown(): void
    {
        if (!isset($this->domain) || !\is_dir(APP_STORAGE_CERTIFICATES . '/' . $this->domain)) {
            return;
        }

        \unlink(APP_STORAGE_CERTIFICATES . '/' . $this->domain . '/cert.pem');
        \rmdir(APP_STORAGE_CERTIFICATES . '/' . $this->domain);
    }

    public function testCertificateExpiryIsReadFromTheStoredCertificate(): void
    {
        $key = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $request = \openssl_csr_new(['commonName' => $this->domain], $key);
        \openssl_x509_export(\openssl_csr_sign($request, null, $key, 30), $pem);
        \mkdir(APP_STORAGE_CERTIFICATES . '/' . $this->domain, 0755, true);
        \file_put_contents(APP_STORAGE_CERTIFICATES . '/' . $this->domain . '/cert.pem', $pem);

        $expiry = (new LetsEncrypt('certificates@example.com'))->getCertificateExpiry($this->domain, null);

        $this->assertNotNull($expiry);
        $this->assertEqualsWithDelta(\time() + 30 * 24 * 60 * 60, \strtotime($expiry), 120);
    }

    public function testCertificateExpiryIsUnknownWithoutACertificate(): void
    {
        $this->assertNull((new LetsEncrypt('certificates@example.com'))->getCertificateExpiry($this->domain, null));
    }
}
