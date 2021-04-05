<?php

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Domains\Domain;
use Appwrite\Database\Database;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Network\Validator\CNAME;

require_once __DIR__.'/../init.php';

Console::title('Certificates V1 Worker');

Console::success(APP_NAME.' certificates worker v1 has started');

class CertificatesV1
{
    public $args = [];

    public function setUp(): void
    {
    }

    public function perform()
    {
        global $register;

        $consoleDB = new Database();
        $consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
        $consoleDB->setNamespace('app_console'); // Main DB
        $consoleDB->setMocks(Config::getParam('collections', []));

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

        Authorization::disable();

        // Args
        $document = $this->args['document'];
        $domain = $this->args['domain'];

        // Validation Args
        $validateTarget = $this->args['validateTarget'] ?? true;
        $validateCNAME = $this->args['validateCNAME'] ?? true;
        
        // Options
        $domain = new Domain((!empty($domain)) ? $domain : '');
        $expiry = 60 * 60 * 24 * 30 * 2; // 60 days
        $safety = 60 * 60; // 1 hour
        $renew  = (\time() + $expiry);

        if(empty($domain->get())) {
            throw new Exception('Missing domain');
        }

        if(!$domain->isKnown() || $domain->isTest()) {
            throw new Exception('Unknown public suffix for domain');
        }

        if($validateTarget) {
            $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));
    
            if(!$target->isKnown() || $target->isTest()) {
                throw new Exception('Unreachable CNAME target ('.$target->get().'), please use a domain with a public suffix.');
            }
        }

        if($validateCNAME) {
            $validator = new CNAME($target->get()); // Verify Domain with DNS records
    
            if(!$validator->isValid($domain->get())) {
                throw new Exception('Failed to verify domain DNS records');
            }
        }

        $certificate = $consoleDB->getCollectionFirst([
            'limit' => 1,
            'offset' => 0,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_CERTIFICATES,
                'domain='.$domain->get(),
            ],
        ]);

        // $condition = ($certificate
        //     && $certificate instanceof Document
        //     && isset($certificate['issueDate'])
        //     && (($certificate['issueDate'] + ($expiry)) > time())) ? 'true' : 'false';

        // throw new Exception('cert issued at'.date('d.m.Y H:i', $certificate['issueDate']).' | renew date is: '.date('d.m.Y H:i', ($certificate['issueDate'] + ($expiry))).' | condition is '.$condition);

        $certificate = (!empty($certificate) && $certificate instanceof $certificate) ? $certificate->getArrayCopy() : [];

        $staging = (App::isProduction()) ? '' : ' --dry-run';
        $email = App::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS');

        if(empty($email)) {
            throw new Exception('You must set a valid security email address (_APP_SYSTEM_SECURITY_EMAIL_ADDRESS) to issue an SSL certificate');
        }

        $stdout = '';
        $stderr = '';

        $exit = Console::execute("certbot certonly --webroot --noninteractive --agree-tos{$staging}"
            ." --email ".$email
            ." -w ".APP_STORAGE_CERTIFICATES
            ." --config-dir ".APP_STORAGE_CERTIFICATES
            ." --keep-until-expiring " // Default is to renew 30 days before expiration.
            ." -d {$domain->get()}", '', $stdout, $stderr);

        if($exit !== 0) {
            throw new Exception('Failed to issue a certificate with message: '.$stderr);
        }

        $certificate = \array_merge($certificate, [
            '$collection' => Database::SYSTEM_COLLECTION_CERTIFICATES,
            '$permissions' => [
                'read' => [],
                'write' => [],
            ],
            'domain' => $domain->get(),
            'issueDate' => \time(),
            'renewDate' => $renew,
            'attempts' => 0,
            'log' => \json_encode($stdout),
        ]);

        $certificate = $consoleDB->createDocument($certificate);

        if(!$certificate) {
            throw new Exception('Failed saving certificate to DB');
        }

        if(!empty($document)) {
            $document = \array_merge($document, [
                'updated' => \time(),
                'certificateId' => $certificate->getId(),
            ]);
    
            $document = $consoleDB->updateDocument($document);
    
            if(!$document) {
                throw new Exception('Failed saving domain to DB');
            }
        }
        
        $config = 
"tls:
  certificates:
    - certFile: /storage/certificates/live/{$domain->get()}/fullchain.pem
      keyFile: /storage/certificates/live/{$domain->get()}/privkey.pem";

        if(!\file_put_contents(APP_STORAGE_CONFIG.'/'.$domain->get().'.yml', $config)) {
            throw new Exception('Failed to save SSL configuration');
        }

        ResqueScheduler::enqueueAt($renew + $safety, 'v1-certificates', 'CertificatesV1', [
            'document' => [],
            'domain' => $domain->get(),
            'validateTarget' => $validateTarget,
            'validateCNAME' => $validateCNAME,
        ]);  // Async task rescheduale

        Authorization::reset();
    }

    public function tearDown(): void
    {
        // ... Remove environment for this job
    }
}
