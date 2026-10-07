<?php

declare(strict_types=1);

namespace Tests\Unit\Certificates;

use Appwrite\Certificates\LetsEncrypt;
use Exception;
use PHPUnit\Framework\TestCase;

final class LetsEncryptTest extends TestCase
{
    private string|false $previousCertificates;

    private string|false $previousSecurity;

    protected function setUp(): void
    {
        $this->previousCertificates = getenv('_APP_EMAIL_CERTIFICATES');
        $this->previousSecurity = getenv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS');
    }

    protected function tearDown(): void
    {
        $this->restore('_APP_EMAIL_CERTIFICATES', $this->previousCertificates);
        $this->restore('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', $this->previousSecurity);
    }

    public function testMissingEmailRejectsIssuanceAndStillDeletesFiles(): void
    {
        putenv('_APP_EMAIL_CERTIFICATES');
        putenv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS');

        $certificates = LetsEncrypt::fromEnvironment();
        $domain = 'delete-' . bin2hex(random_bytes(8)) . '.example.test';
        $directory = APP_STORAGE_CERTIFICATES . '/' . $domain;

        try {
            $certificates->deleteCertificate($domain, 'site');

            if (!is_dir(APP_STORAGE_CERTIFICATES) && !mkdir(APP_STORAGE_CERTIFICATES, 0755, true) && !is_dir(APP_STORAGE_CERTIFICATES)) {
                $this->fail('Certificate storage is not writable');
            }

            $this->assertTrue(mkdir($directory));
            $this->assertNotFalse(file_put_contents($directory . '/cert.pem', 'cert'));
            $this->assertNotFalse(file_put_contents($directory . '/privkey.pem', 'key'));

            $certificates->deleteCertificate($domain, null);

            $this->assertDirectoryDoesNotExist($directory);

            $this->expectException(Exception::class);
            $this->expectExceptionMessage('You must set a valid security email address (_APP_EMAIL_CERTIFICATES) to issue a LetsEncrypt SSL certificate.');
            $certificates->issueCertificate('cert-name', $domain, null);
        } finally {
            if (is_dir($directory)) {
                $files = glob($directory . '/*');
                if (is_array($files)) {
                    array_map(unlink(...), $files);
                }
                rmdir($directory);
            }
        }
    }

    public function testSecurityEmailAllowsIssuanceWhenCertificatesEmailIsEmpty(): void
    {
        putenv('_APP_EMAIL_CERTIFICATES');
        putenv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS=admin@example.test');

        $this->expectNotToPerformAssertions();
        LetsEncrypt::fromEnvironment()->assertCanIssue();
    }

    public function testCertificatesEmailAllowsIssuanceWithoutSecurityEmail(): void
    {
        putenv('_APP_EMAIL_CERTIFICATES=certs@example.test');
        putenv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS');

        $this->expectNotToPerformAssertions();
        LetsEncrypt::fromEnvironment()->assertCanIssue();
    }

    private function restore(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
    }
}
