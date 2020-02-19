<?php

require_once __DIR__.'/../init.php';

cli_set_process_title('Certificates V1 Worker');

echo APP_NAME.' certificates worker v1 has started';

class CertsV1
{
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $register;

        $domain = $this->args['domain'];

        /**
         * 1. Get new domain
         * 2. Fetch all subdomains
         * 3. Check if certificate already exists
         * 4. Check if certificate has been changed
         *  4.1. Create / renew certificate
         *  4.2. Update loadblancer
         *  4.3. Update certificate (domains, change date, expiry)
         */
         $response = shell_exec("certbot certonly --webroot --noninteractive --agree-tos --email security@appwrite.io \
            -w ./certs \
            -d {$domain}"); // cert2.tests.appwrite.org

        
    
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}
