<?php

namespace Appwrite\Certificates;

use Exception;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Logger\Log;

class LetsEncrypt implements Adapter
{
    private string $email;

    public function __construct(string $email)
    {
        $this->email = $email;
    }


    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $stdout = '';
        $stderr = '';

        $staging = (App::isProduction()) ? '' : ' --dry-run';
        $exit = Console::execute(
            "certbot certonly -v --webroot --noninteractive --agree-tos{$staging}"
            . " --email " . $this->email
            . " --cert-name " . $certName
            . " -w " . APP_STORAGE_CERTIFICATES
            . " -d {$domain}",
            '',
            $stdout,
            $stderr
        );

        // Unexpected error, usually 5XX, API limits, ...
        if ($exit !== 0) {
            throw new Exception('Failed to issue a certificate with message: ' . $stderr);
        }

        // Prepare folder in storage for domain
        $path = APP_STORAGE_CERTIFICATES . '/' . $domain;
        if (!\is_readable($path)) {
            if (!\mkdir($path, 0755, true)) {
                throw new Exception('Failed to create path for certificate.');
            }
        }

        // Move generated files
        if (!@\rename('/etc/letsencrypt/live/' . $certName . '/cert.pem', APP_STORAGE_CERTIFICATES . '/' . $domain . '/cert.pem')) {
            throw new Exception('Failed to rename certificate cert.pem. Let\'s Encrypt log: ' . $stderr . ' ; ' . $stdout);
        }

        if (!@\rename('/etc/letsencrypt/live/' . $certName . '/chain.pem', APP_STORAGE_CERTIFICATES . '/' . $domain . '/chain.pem')) {
            throw new Exception('Failed to rename certificate chain.pem. Let\'s Encrypt log: ' . $stderr . ' ; ' . $stdout);
        }

        if (!@\rename('/etc/letsencrypt/live/' . $certName . '/fullchain.pem', APP_STORAGE_CERTIFICATES . '/' . $domain . '/fullchain.pem')) {
            throw new Exception('Failed to rename certificate fullchain.pem. Let\'s Encrypt log: ' . $stderr . ' ; ' . $stdout);
        }

        if (!@\rename('/etc/letsencrypt/live/' . $certName . '/privkey.pem', APP_STORAGE_CERTIFICATES . '/' . $domain . '/privkey.pem')) {
            throw new Exception('Failed to rename certificate privkey.pem. Let\'s Encrypt log: ' . $stderr . ' ; ' . $stdout);
        }

        $config = \implode(PHP_EOL, [
            "tls:",
            "  certificates:",
            "    - certFile: /storage/certificates/{$domain}/fullchain.pem",
            "      keyFile: /storage/certificates/{$domain}/privkey.pem"
        ]);

        // Save configuration into Traefik using our new cert files
        if (!\file_put_contents(APP_STORAGE_CONFIG . '/' . $domain . '.yml', $config)) {
            throw new Exception('Failed to save Traefik configuration.');
        }

        $certPath = APP_STORAGE_CERTIFICATES . '/' . $domain . '/cert.pem';
        $certData = openssl_x509_parse(file_get_contents($certPath));
        $validTo = $certData['validTo_time_t'] ?? null;
        $dt = (new \DateTime())->setTimestamp($validTo);
        return DateTime::addSeconds($dt, -60 * 60 * 24 * 30);
    }

    public function isRenewRequired(string $domain, ?string $domainType, Log $log): bool
    {
        $certPath = APP_STORAGE_CERTIFICATES . '/' . $domain . '/cert.pem';
        if (\file_exists($certPath)) {
            $certData = openssl_x509_parse(file_get_contents($certPath));
            $validTo = $certData['validTo_time_t'] ?? 0;

            if (empty($validTo)) {
                $log->addTag('certificateDomain', $domain);
                throw new Exception('Unable to read certificate file (cert.pem).');
            }

            // LetsEncrypt allows renewal 30 days before expiry
            $expiryInAdvance = (60 * 60 * 24 * 30);
            if ($validTo - $expiryInAdvance > \time()) {
                $log->addTag('certificateDomain', $domain);
                $log->addExtra('certificateData', \is_array($certData) ? \json_encode($certData) : \strval($certData));
                return false;
            }
        }

        return true;
    }

    public function deleteCertificate(string $domain): void
    {
        $directory = APP_STORAGE_CERTIFICATES . '/' . $domain;
        $checkTraversal = realpath($directory) === $directory;

        if ($checkTraversal && is_dir($directory)) {
            // Delete files, so Traefik is aware of change
            array_map('unlink', glob($directory . '/*.*'));
            rmdir($directory);
            Console::info("Deleted certificate files for {$domain}");
        } else {
            Console::info("No certificate files found for {$domain}");
        }
    }
}
