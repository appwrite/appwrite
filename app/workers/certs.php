<?php

require_once __DIR__.'/../init.php';

cli_set_process_title('Certs V1 Worker');

echo APP_NAME.' certs worker v1 has started';

class CertsV1
{
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $register;


        /**
         * 1. Get new domain
         * 2. Fetch all subdomains
         * 3. Check if certificate already exists
         * 4. Check if certificate has been changed
         *  4.1. Create / Renew certificate
         *  4.2. Update loadblancer
         *  4.3. Update certificate (domains, change date, expiry)
         */
        
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}
