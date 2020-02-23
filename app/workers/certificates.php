<?php

use Database\Database;
use Database\Document;
use Database\Validator\Authorization;
use Network\Validators\CNAME;
use Utopia\Domains\Domain;

require_once __DIR__.'/../init.php';

cli_set_process_title('Certificates V1 Worker');

echo APP_NAME.' certificates worker v1 has started';

class CertificatesV1
{
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $request, $consoleDB;

        /**
         * 1. Get new domain document - DONE
         *  1.1. Validate domain is valid, public suffix is known and CNAME records are verified - DONE
         * 2. Check if a certificate already exists - DONE
         * 3. Check if certificate is not about to expire skip
         *  3.1. Create / renew certificate
         *  3.2. Update loadblancer
         *  3.3. Update database (domains, change date, expiry)
         *  3.4. Set retry on failure
         */

        Authorization::disable();

        $document = $this->args['document'];
        $domain = new Domain((isset($document['domain'])) ? $document['domain'] : '');
        $expiry = 60 * 60 * 24 * 30 * 2; // 60 days

        if(empty($domain->get())) {
            throw new Exception('Missing domain');
        }

        if(!$domain->isKnown() || $domain->isTest()) {
            throw new Exception('Unkown public suffix for domain');
        }

        $target = new Domain($request->getServer('_APP_DOMAINS_TARGET', ''));

        if(!$target->isKnown() || $target->isTest()) {
            throw new Exception('Unreachable CNAME target ('.$target->get().'), plesse use a domain with a public suffix.', 500);
        }

        $validator = new CNAME($target->get()); // Verify Domain with DNS records

        if(!$validator->isValid($domain->get())) {
            throw new Exception('Failed to verify domain DNS records');
        }

        $certificate = $consoleDB->getCollection([
            'limit' => 1,
            'offset' => 0,
            'orderField' => 'id',
            'orderType' => 'ASC',
            'orderCast' => 'string',
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_CERTIFICATES,
                'domain='.$domain->get(),
            ],
            'first' => true,
        ]);

        $certificate = (!empty($certificate) && $certificate instanceof $certificate) ? $certificate->getArrayCopy() : [];

        if($certificate
            && $certificate instanceof Document
            && isset($certificate['issueDate'])
            && ($certificate['issueDate'] + $expiry > time())) { // Check last issue time
                throw new Exception('Renew isn\'t required. Domain issued at '.date('d.m.Y H:i', (isset($certificate['issueDate']) ? $certificate['issueDate'] : 0)));
        }

        $response = shell_exec("certbot certonly --webroot --noninteractive --agree-tos --email security@appwrite.io \
            -w ".APP_STORAGE_CERTIFICATES." \
            -d {$domain->get()} 2>&1"); // cert2.tests.appwrite.org

        if(!$response) {
            throw new Exception('Failed to issue a certificate');
        }
        
        if(!rename('/etc/letsencrypt/live/'.$domain->get(), APP_STORAGE_CERTIFICATES.'/'.$domain->get())) {
            throw new Exception('Failed to copy certificate');
        }

        $certificate = array_merge($certificate, [
            '$collection' => Database::SYSTEM_COLLECTION_CERTIFICATES,
            '$permissions' => [
                'read' => [],
                'write' => [],
            ],
            'domain' => $domain->get(),
            'issueDate' => time(),
            'attempts' => 0,
            'log' => json_encode($response),
        ]);

        $certificate = $consoleDB->createDocument($certificate);

        if(!$certificate) {
            throw new Exception('Failed saving certificate to DB');
        }

        $document = array_merge($document, [
            'updated' => time(),
            'certificateId' => $certificate->getId(),
        ]);

        $document = $consoleDB->updateDocument($document);

        if(!$document) {
            throw new Exception('Failed saving domain to DB');
        }

        Authorization::reset();
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}
