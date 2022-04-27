<?php

use Appwrite\Event\Event;
use Appwrite\Network\Validator\CNAME;
use Appwrite\Resque\Worker;
use Appwrite\Exception\Certificate as ExceptionCertificate;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Domains\Domain;

require_once __DIR__.'/../init.php';

Console::title('Certificates V1 Worker');
Console::success(APP_NAME . ' certificates worker v1 has started');

class CertificatesV1 extends Worker
{
    private $certificate = null; // run function fills this. onError callback uses it

    public function getName(): string {
        return "certificates";
    }

    public function init(): void
    {
        
    }

    public function run(): void
    {
        Authorization::disable();

        $dbForConsole = $this->getConsoleDB();

        $this->certificate = new Document();

        /**
         * 1. Read arguments and validate domain
         * 2. Get main domain
         * 3. Validate CNAME DNS if parameter is not main domain (meaning it's custom domain)
         * 4. Validate renew date with certificate file, unless requested to skip by parameter
         * 5. Validate security email. Cannot be empty, required by LetsEncrypt
         * 6. Issue a certificate using certbot CLI
         * 7. Update 'log' attribute on certificate document with Certbot message
         * 8. Create storage folder for certificate, if not ready already
         * 9. Move certificates from Certbot location to our Storage
         * 10. Create/Update our Storage with new Traefik config with new certificate paths
         * 11. Read certificate file and update 'renewDate' on certificate document
         * 12. Update 'issueDate' and 'attempts' on certificate
         * 
         * If at any point unexpected error occurs, program stops without applying changes to document, and error is thrown into worker
         * 
         * If code stops with expected error:
         * 1. 'log' attribute on document is updated with error message
         * 2. 'attempts' amount is increased
         * 3. Console log is shown
         * 4. Email is sent to security email
         * 
         * Unless unexpected error occurs, at the end, we:
         * 1. Update 'updated' attribute on document
         * 2. Save document to database
         * 3. Update all domains documents with current certificate ID
         * 
         * Note: Renewals are checked and scheduled from maintenence worker
         */

        try {
            // Get attributes
            $domain = $this->args['domain']; // String of domain (hostname)
            $domain = new Domain((!empty($domain)) ? $domain : '');

            $this->certificate->setAttribute('domain', $domain->get());
    
            $skipRenewCheck = $this->args['skipRenewCheck'] ?? false; // If true, we won't double-check expiry from cert file
    
            $mainDomain = null; // ENV or first ever visited domain
            if (!empty(App::getEnv('_APP_DOMAIN', ''))) {
                $mainDomain = App::getEnv('_APP_DOMAIN', '');
            } else {
                $domainDocument = $dbForConsole->findOne('domains', [], 0, ['_id'], ['ASC']);
                $mainDomain = $domainDocument ? $domainDocument->getAttribute('domain') : $domain->get();
            }
    
            // If not main domain, we will check CNAME record
            $validateCNAME = false;
            if ($domain->get() !== $mainDomain) {
                $validateCNAME = true;
            }
    
            if (empty($domain->get())) {
                throw new ExceptionCertificate('Missing certificate domain.');
            }
    
            if (!$domain->isKnown() || $domain->isTest()) {
                throw new ExceptionCertificate('Unknown public suffix for domain.');
            }
    
            if ($validateCNAME) {
                // TODO: Would be awesome to also support A/AAAA records here. Maybe dry run?
    
                // Validate if domain target is properly configured
                $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));
    
                if (!$target->isKnown() || $target->isTest()) {
                    throw new ExceptionCertificate('Unreachable CNAME target ('.$target->get().'), please use a domain with a public suffix.');
                }
    
                // Verify domain with DNS records
                $validator = new CNAME($target->get());
                if (!$validator->isValid($domain->get())) {
                    throw new ExceptionCertificate('Failed to verify domain DNS records.');
                }
            } else {
                // Main domain validation
                // TODO: Would be awesome to check A/AAAA record here. Maybe dry run?
            }

            // If certificate exists already, double-check expiry date
            // If asked to skip, we won't
            $certPath = APP_STORAGE_CERTIFICATES . '/' . $domain->get() . '/cert.pem';
            if (!$skipRenewCheck && \file_exists($certPath)) {
                $validTo = null;

                try {
                    $certData = openssl_x509_parse(file_get_contents($certPath));
    
                    $validTo = $certData['validTo_time_t'] ?? 0;
    
                    if (empty($validTo)) {
                        throw new Exception('Invalid expiry date.');
                    }
                } catch(\Throwable $th) {
                    throw new ExceptionCertificate('Unable to read certificate file (cert.pem).');
                }

                // LetsEncrypt allows renewal 30 days before expiry
                $expiryInAdvance = (60*60*24*30);
                if ($validTo - $expiryInAdvance > \time()) {
                    $validToVerbose = date('d-m-Y H:i:s', $validTo);
                    throw new ExceptionCertificate('Renew isn\'t required. Next renew at ' . $validToVerbose);
                }
            }
    
            // Email for alerts is required by LetsEncrypt
            $email = App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS');
            if (empty($email)) {
                throw new ExceptionCertificate('You must set a valid security email address (_APP_SYSTEM_SECURITY_EMAIL_ADDRESS) to issue an SSL certificate.');
            }

            // LetsEncrypt communication to issue certificate (using certbot CLI)
            $stdout = '';
            $stderr = '';
    
            $staging = (App::isProduction()) ? '' : ' --dry-run';
            $exit = Console::execute("certbot certonly --webroot --noninteractive --agree-tos{$staging}"
                . " --email " . $email
                . " -w " . APP_STORAGE_CERTIFICATES
                . " -d {$domain->get()}", '', $stdout, $stderr);
            
            // All exceptions from now on will be marked to increment attempts count. This allows us to only limit attempts for domains that failed on LectEncrypt side.
            // Such attempts count allows us to prevent API limit abuse with always failing domains
    
            // Unexpected error, usually 5XX, API limits, ...
            if ($exit !== 0) {
                throw new ExceptionCertificate('Failed to issue a certificate with message: ' . $stderr);
            }

            // Command succeeded, store all data into document
            // We store stderr too, because it may include warnings
            // This is only stored if everytng below passes too. Otherwise, it will be overwritten by error message
            $this->certificate->setAttribute('log', \json_encode([
                'stdout' => $stdout,
                'stderr' => $stderr,
            ]));
    
            // Prepare folder in storage for domain
            $path = APP_STORAGE_CERTIFICATES . '/' . $domain->get();
            if (!\is_readable($path)) {
                if (!\mkdir($path, 0755, true)) {
                    throw new ExceptionCertificate('Failed to create path for certificate.');
                }
            }
    
            // Move generated files from certbot into our storage
            if(!@\rename('/etc/letsencrypt/live/'.$domain->get().'/cert.pem', APP_STORAGE_CERTIFICATES.'/'.$domain->get().'/cert.pem')) {
                throw new ExceptionCertificate('Failed to rename certificate cert.pem: '.\json_encode($stdout));
            }
    
            if (!@\rename('/etc/letsencrypt/live/' . $domain->get() . '/chain.pem', APP_STORAGE_CERTIFICATES . '/' . $domain->get() . '/chain.pem')) {
                throw new ExceptionCertificate('Failed to rename certificate chain.pem: ' . \json_encode($stdout));
            }
    
            if (!@\rename('/etc/letsencrypt/live/' . $domain->get() . '/fullchain.pem', APP_STORAGE_CERTIFICATES . '/' . $domain->get() . '/fullchain.pem')) {
                throw new ExceptionCertificate('Failed to rename certificate fullchain.pem: ' . \json_encode($stdout));
            }
    
            if (!@\rename('/etc/letsencrypt/live/' . $domain->get() . '/privkey.pem', APP_STORAGE_CERTIFICATES . '/' . $domain->get() . '/privkey.pem')) {
                throw new ExceptionCertificate('Failed to rename certificate privkey.pem: ' . \json_encode($stdout));
            }

            // This multi-line syntax helps IDE
            $config =
                "tls:" .
                "  certificates:" . 
                "    - certFile: /storage/certificates/{$domain->get()}/fullchain.pem" . 
                "      keyFile: /storage/certificates/{$domain->get()}/privkey.pem";
            
            // Save configuration into Traefik using our new cert files
            if (!\file_put_contents(APP_STORAGE_CONFIG . '/' . $domain->get() . '.yml', $config)) {
                throw new ExceptionCertificate('Failed to save Traefik configuration.');
            }

            // Read new renew date from cert file
            // TODO: This might not be required, we could calculate it. But this feels safer
            $certPath = APP_STORAGE_CERTIFICATES . '/' . $domain->get() . '/cert.pem';
            $certData = openssl_x509_parse(file_get_contents($certPath));
            $validTo = $certData['validTo_time_t'] ?? 0;
            $expiryInAdvance = (60*60*24*30);
            $this->certificate->setAttribute('renewDate', $validTo - $expiryInAdvance);

            // All went well at this point 🥳
            
            // Reset attempts count for next renwal
            $this->certificate->setAttribute('attempts', 0);

            // Mark issue date
            $this->certificate->setAttribute('issueDate', \time());
        } catch(ExceptionCertificate $e) {
            // These exceptions are expected if renew shouldn't or can't happen

            // Add exception as log into certificate
            $this->certificate->setAttribute('log', $e->getMessage());

            $attempt = $this->certificate->getAttribute('attempts', 0);
            $attempt++;
            
            // Save increased attempts count
            $this->certificate->setAttribute('attempts', $attempt);

            Console::warning('Cannot renew domain (' . $domain->get() . ') on attempt no. ' . $attempt . ' certificate: ' . $e->getMessage());

            // Send email to security email
            Resque::enqueue(Event::MAILS_QUEUE_NAME, Event::MAILS_CLASS_NAME, [
                'from' => 'console',
                'project' => 'console',
                'name' => 'Appwrite Administrator',
                'recipient' => App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS'),
                'url' => 'https://' . $domain->get(),
                'locale' => App::getEnv('_APP_LOCALE', 'en'),
                'type' => MAIL_TYPE_CERTIFICATE,

                'domain' => $domain->get(),
                'error' => $e->getMessage(),
                'attempt' => $attempt
            ]);
        } finally {
            // All actions result in new updatedAt date
            $this->certificate->setAttribute('updated', \time());

            // Save certificate data into database
            // Check if update or insert required
            $certificateDocument = $dbForConsole->findOne('certificates', [ new Query('domain', Query::TYPE_EQUAL, [$domain->get()]) ]);
            if (!empty($certificateDocument) && !$certificateDocument->isEmpty()) {
                // Merge new data with current data
                $this->certificate = new Document(\array_merge($certificateDocument->getArrayCopy(), $this->certificate->getArrayCopy()));
                
                $this->certificate = $dbForConsole->updateDocument('certificates', $this->certificate->getId(), $this->certificate);
            } else {
                $this->certificate = $dbForConsole->createDocument('certificates', $this->certificate);
            }

            // Update domains with new certificate ID
            $certificateId = $this->certificate->getId();

            $domains = $dbForConsole->find('domains', [
                new Query('domain', Query::TYPE_EQUAL, [$domain->get()])
            ], 1000);

            foreach ($domains as $domainDocument) {
                $domainDocument->setAttribute('updated', \time());
                $domainDocument->setAttribute('certificateId', $certificateId);

                $dbForConsole->updateDocument('domains', $domainDocument->getId(), $domainDocument);
                $dbForConsole->deleteCachedDocument('projects', $domainDocument->getAttribute('projectId'));
            }

            Authorization::reset();
        }
    }

    public function shutdown(): void
    {
    }
}
