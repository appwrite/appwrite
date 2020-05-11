<?php

use Utopia\CLI\Console;
use Utopia\Config\Config;
use Appwrite\Database\Database;
use Appwrite\Database\Validator\Authorization;

require_once __DIR__.'/../init.php';

cli_set_process_title('Functions V1 Worker');

Console::success(APP_NAME.' functions worker v1 has started');

$environments = Config::getParam('environments');

foreach($environments as $environment) {
    $stdout = '';
    $stderr = '';

    Console::info('Warming up '.$environment['name'].' environment');

    Console::execute('docker pull '.$environment['image'], null, $stdout, $stderr);

    if(!empty($stdout)) {
        Console::success($stdout);
    }

    if(!empty($stderr)) {
        Console::error($stderr);
    }
}

class FunctionsV1
{
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $environments;

        /**
         * 1. Get event args
         * 2. Unpackage code in an isolated folder
         * 3. Execute in container with timeout + messure execution time
         * 4. Update execution status
         * 5. Update execution stdout & stderr
         * 6. Trigger audit log
         * 7. Trigger usage log
         */
        $stdout = '';
        $stderr = '';
        $image  = 'php:7.4-cli';
        $timeout  = 15;

        $start = microtime(true);

        /**
         * Limit CPU Usage
         * Limit Memory Usage
         * Limit Network Usage
         * Make sure no access to redis, mariadb, influxdb or other system services
         * Make sure no access to NFS server / storage volumes
         * Access Appwrite REST from internal network for improved performance
         */
        Console::execute("docker run \
            --rm \
            -v $(pwd):/app \
            -w /app \
            {$image} \
            php -v", null, $stdout, $stderr, $timeout);

        $end = microtime(true);

        echo "The code took " . ($end - $start) . " seconds to complete.";
    }

    public function tearDown()
    {
    }
}
