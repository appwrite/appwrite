<?php

use Appwrite\Event\Event;
use Appwrite\Network\Validator\CNAME;
use Appwrite\Resque\Worker;
use PHPUnit\Framework\Constraint\Operator;
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
    // Refference: https://letsencrypt.org/docs/rate-limits/
    const LETSENCRYPT_API_LIMIT = 300; // 30 orders
    const LETSENCRYPT_API_LIMIT_INTERVAL = 10800; // 3 hours

    public function getName(): string {
        return "certificates";
    }

    public function init(): void
    {
        // We trigger once, to start recursive renewal loop
        $this->renewCertificate();
    }

    public function run(): void
    {
        Authorization::disable();

        $type = $this->args['type'] ?? '';
        switch (strval($type)) {
            case CERTIFICATE_TYPE_RENEW:
                $this->renewCertificate();
                break;
            case CERTIFICATE_TYPE_ISSUE:
                $this->issueCertificate($this->args);
                break;
        }

        Authorization::reset();
    }

    // This is recursive function
    protected function renewCertificate() {
        $dbForConsole = $this->getConsoleDB();

        // We will renew all certs older than 60 days
        // Refference: https://letsencrypt.org/docs/faq/
        // "Our certificates are valid for 90 days. We recommend automatically renewing your certificates every 60 days."
        $certificateForRenewal = $dbForConsole->findOne("certificates", [
            new Query('result', Query::TYPE_EQUAL, ['done', 'apiLimit']),
            new Query('updated', Query::TYPE_LESSEREQUAL, [ \time() - 5184000 ]), // - 60 days
        ]);

        if($certificateForRenewal) {
            $this->issueCertificate([
                'domain' => $certificateForRenewal->getAttribute('domain'),
            ]);
        }

        // When done, schedule again in 1 minute. This creates makes the function recursive.
        ResqueScheduler::enqueueAt(\time() + 60, Event::CERTIFICATES_QUEUE_NAME, Event::CERTIFICATES_CLASS_NAME, [
            'type' => CERTIFICATE_TYPE_RENEW
        ]);
    }

    protected function issueCertificate($args) {
        /**
         * 1. Get new domain document - DONE
         *  1.1. Validate domain is valid, public suffix is known and CNAME records are verified - DONE
         * 2. Check if a certificate already exists - DONE
         * 3. Check if certificate is about to expire, if not - skip it
         *  3.1. Create / renew certificate
         *  3.2. Update loadblancer
         *  3.3. Update database (domains, change date, expiry)
         *  3.4. Set retry on failure
         *  3.5. Schedule to renew certificate in 60 days
         */

        $dbForConsole = $this->getConsoleDB();

        // Args
        $document = $args['document'];
        $domain = $args['domain'];

        // Validation Args
        $validateTarget = $args['validateTarget'] ?? true;
        $validateCNAME = $args['validateCNAME'] ?? true;

        // Options
        $domain = new Domain((!empty($domain)) ? $domain : '');
        $expiry = 60 * 60 * 24 * 30 * 2; // 60 days
        $safety = 60 * 60; // 1 hour
        $renew  = (\time() + $expiry);

        if (empty($domain->get())) {
            throw new Exception('Missing domain');
        }

        if (!$domain->isKnown() || $domain->isTest()) {
            throw new Exception('Unknown public suffix for domain');
        }

        if ($validateTarget) {
            $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));

            if(!$target->isKnown() || $target->isTest()) {
                throw new Exception('Unreachable CNAME target ('.$target->get().'), please use a domain with a public suffix.');
            }
        }

        if ($validateCNAME) {
            $validator = new CNAME($target->get()); // Verify Domain with DNS records

            if(!$validator->isValid($domain->get())) {
                throw new Exception('Failed to verify domain DNS records');
            }
        }

        $certificate = $dbForConsole->findOne('certificates', [
            new Query('domain', QUERY::TYPE_EQUAL, [$domain->get()])
        ]);

        // $condition = ($certificate
        //     && $certificate instanceof Document
        //     && isset($certificate['issueDate'])
        //     && (($certificate['issueDate'] + ($expiry)) > time())) ? 'true' : 'false';

        // throw new Exception('cert issued at'.date('d.m.Y H:i', $certificate['issueDate']).' | renew date is: '.date('d.m.Y H:i', ($certificate['issueDate'] + ($expiry))).' | condition is '.$condition);

        $certificateArray = (!empty($certificate) && $certificate instanceof $certificate) ? $certificate->getArrayCopy() : [];

        if (
            !empty($certificateArray)
            && isset($certificateArray['issueDate'])
            && (($certificateArray['issueDate'] + ($expiry)) > \time())
        ) { // Check last issue time
            throw new Exception('Renew isn\'t required');
        }

        $staging = (App::isProduction()) ? '' : ' --dry-run';
        $email = App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS');

        if (empty($email)) {
            throw new Exception('You must set a valid security email address (_APP_SYSTEM_SECURITY_EMAIL_ADDRESS) to issue an SSL certificate');
        }

        try {
            $stdout = '';
            $stderr = '';
    
            $exit = Console::execute("certbot certonly --webroot --noninteractive --agree-tos{$staging}"
                . " --email " . $email
                . " -w " . APP_STORAGE_CERTIFICATES
                . " -d {$domain->get()}", '', $stdout, $stderr);
    
            if ($exit !== 0) {
                throw new Exception('Failed to issue a certificate with message: ' . $stderr);
            }
    
            $path = APP_STORAGE_CERTIFICATES . '/' . $domain->get();
    
            if (!\is_readable($path)) {
                if (!\mkdir($path, 0755, true)) {
                    throw new Exception('Failed to create path...');
                }
            }
    
            if(!@\rename('/etc/letsencrypt/live/'.$domain->get().'/cert.pem', APP_STORAGE_CERTIFICATES.'/'.$domain->get().'/cert.pem')) {
                throw new Exception('Failed to rename certificate cert.pem: '.\json_encode($stdout));
            }
    
            if (!@\rename('/etc/letsencrypt/live/' . $domain->get() . '/chain.pem', APP_STORAGE_CERTIFICATES . '/' . $domain->get() . '/chain.pem')) {
                throw new Exception('Failed to rename certificate chain.pem: ' . \json_encode($stdout));
            }
    
            if (!@\rename('/etc/letsencrypt/live/' . $domain->get() . '/fullchain.pem', APP_STORAGE_CERTIFICATES . '/' . $domain->get() . '/fullchain.pem')) {
                throw new Exception('Failed to rename certificate fullchain.pem: ' . \json_encode($stdout));
            }
    
            if (!@\rename('/etc/letsencrypt/live/' . $domain->get() . '/privkey.pem', APP_STORAGE_CERTIFICATES . '/' . $domain->get() . '/privkey.pem')) {
                throw new Exception('Failed to rename certificate privkey.pem: ' . \json_encode($stdout));
            }
    
            if(empty($certificateArray)) {
                $certificate = new Document([
                    'domain' => $domain->get(),
                    'issueDate' => \time(),
                    'renewDate' => $renew,
                    'attempts' => 0,
                    'result' => 'done',
                    'log' => \json_encode($stdout),
                ]);
    
                $certificate = $dbForConsole->createDocument('certificates', $certificate);
            } else {
                $certificate = new Document(\array_merge($certificateArray, [
                    'issueDate' => \time(),
                    'renewDate' => $renew,
                    'attempts' => 0,
                    'result' => 'done',
                    'log' => \json_encode($stdout),
                ]));
    
                $certificate = $dbForConsole->updateDocument('certificates', $certificate->getId(), $certificate);
            }
    
            if (!$certificate) {
                throw new Exception('Failed saving certificate to DB');
            }
    
            if(!empty($document)) {
                $certificate = new Document(\array_merge($document, [
                    'updated' => \time(),
                    'certificateId' => $certificate->getId()
                ]));
    
                $certificate = $dbForConsole->updateDocument('domains', $certificate->getId(), $certificate);
    
                if(!$certificate) {
                    throw new Exception('Failed saving domain to DB');
                }
            }
    
            $config =
    "tls:
      certificates:
        - certFile: /storage/certificates/{$domain->get()}/fullchain.pem
          keyFile: /storage/certificates/{$domain->get()}/privkey.pem";
    
            if (!\file_put_contents(APP_STORAGE_CONFIG . '/' . $domain->get() . '.yml', $config)) {
                throw new Exception('Failed to save SSL configuration');
            }
        } catch(Exception $err) {
            $errorType = 'error';

            // TODO: set errorType to apiLimit, if it is apiLimit

            if(empty($certificate)) {
                $certificate = new Document([
                    'domain' => $domain->get(),
                    'issueDate' => \time(),
                    'renewDate' => $renew,
                    'attempts' => 0,
                    'result' => $errorType,
                    'log' => \json_encode([
                        'exception' => $err->getTraceAsString()
                    ]),
                ]);
    
                $certificate = $dbForConsole->createDocument('certificates', $certificate);
            } else {
                $certificate = new Document(\array_merge($certificateArray, [
                    'issueDate' => \time(),
                    'renewDate' => $renew,
                    'attempts' => ((int) $certificateArray['attempts']) + 1,
                    'result' => $errorType,
                    'log' => \json_encode([
                        'exception' => $err->getTraceAsString()
                    ]),
                ]));
    
                $certificate = $dbForConsole->updateDocument('certificates', $certificate->getId(), $certificate);
            }
    
            if (!$certificate) {
                throw new Exception('Failed saving failed certificate to DB');
            }

            throw $err;
        }
    }

    public function shutdown(): void
    {
    }
}
