<?php

require_once __DIR__.'/../init.php';

cli_set_process_title('Functions V1 Worker');

echo APP_NAME.' functions worker v1 has started';

use Utopia\Config\Config;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;

class FunctionsV1
{
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }
}
