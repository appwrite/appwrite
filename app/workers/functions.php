<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use Appwrite\Database\Database;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Validator\Authorization;
use Utopia\CLI\Console;
use Utopia\Config\Config;

require_once __DIR__.'/../init.php';

cli_set_process_title('Functions V1 Worker');

Console::success(APP_NAME.' functions worker v1 has started');

$environments = Config::getParam('environments');

$warmupStart = microtime(true);

Co\run(function() use ($environments) {
    foreach($environments as $environment) { // Warmup: make sure images are ready to run fast 🚀
        go(function() use ($environment) {
            $stdout = '';
            $stderr = '';
        
            Console::info('Warming up '.$environment['name'].' environment');
        
            Console::execute('docker pull '.$environment['image'], null, $stdout, $stderr);
        
            if(!empty($stdout)) {
                Console::log($stdout);
            }
        
            if(!empty($stderr)) {
                Console::error($stderr);
            }
        });
    }
});

$warmupEnd = microtime(true);
$warmupTime = $warmupEnd - $warmupStart;

Console::success('Finished warmup in '.$warmupTime.' seconds');

class FunctionsV1
{
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $environments, $register;

        $projectId = $this->args['projectId'];
        $functionId = $this->args['functionId'];
        $functionTag = $this->args['functionTag'];

        $projectDB = new Database();
        $projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
        $projectDB->setNamespace('app_'.$projectId);
        $projectDB->setMocks(Config::getParam('collections', []));

        Authorization::disable();
        $function = $projectDB->getDocument($functionId);
        Authorization::reset();

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        Authorization::disable();
        $tag = $projectDB->getDocument($functionTag);
        Authorization::reset();

        if($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found', 404);
        }

        $environment = (isset($environments[$function->getAttribute('env', '')]))
            ? $environments[$function->getAttribute('env', '')]
            : null;
        
        if(\is_null($environment)) {
            throw new Exception('Environment "'.$function->getAttribute('env', '').' is not supported');
        }

        /*
         * 1. Get Original Task
         * 2. Check for updates
         *  If has updates skip task and don't reschedule
         *  If status not equal to play skip task
         * 3. Check next run date, update task and add new job at the given date
         * 4. Execute task (set optional timeout)
         * 5. Update task response to log
         *      On success reset error count
         *      On failure add error count
         *      If error count bigger than allowed change status to pause
         */

        /**
         * 1. Get event args
         * 2. Unpackage code in the isolated container
         * 3. Execute in container with timeout
         *      + messure execution time
         *      + pass env vars
         *      + pass one-time api key
         * 4. Update execution status
         * 5. Update execution stdout & stderr
         * 6. Trigger audit log
         * 7. Trigger usage log
         */
        $stdout = '';
        $stderr = '';
        $timeout  = 15;

        $start = \microtime(true);

        //TODO aviod scheduled execution if delay is bigger than X offest

        /**
         * Limit CPU Usage - DONE
         * Limit Memory Usage - DONE
         * Limit Network Usage
         * Make sure no access to redis, mariadb, influxdb or other system services
         * Make sure no access to NFS server / storage volumes
         * Access Appwrite REST from internal network for improved performance
         */

        $tagPath = $tag->getAttribute('codePath', '');
        $tagDir = \pathinfo($tag->getAttribute('codePath', ''), PATHINFO_DIRNAME);
        $tagFile = \pathinfo($tag->getAttribute('codePath', ''), PATHINFO_BASENAME);
        $tagPathTarget = '/tmp/project-'.$projectId.'/'.$tag->getId().'/code.tar.gz';
        $tagPathTargetDir = \pathinfo($tagPathTarget, PATHINFO_DIRNAME);

        if(!\is_readable($tagPath)) {
            throw new Exception('Code is not readable: '.$tag->getAttribute('codePath', ''));
        }

        if (!\file_exists($tagPathTargetDir)) {
            if (!\mkdir($tagPathTargetDir, 0755, true)) {
                throw new Exception('Can\'t create directory '.$tagPathTargetDir);
            }
        }
        
        if (!\file_exists($tagPathTarget)) {
            if(!\copy($tagPath, $tagPathTarget)) {
                throw new Exception('Can\'t create temporary code file '.$tagPathTarget);
            }
        }
        
         //--storage-opt size=120m \
        $exitCode = Console::execute("docker run \
            --cpus=1 \
            --memory=50m \
            --memory-swap=50m \
            --rm \
            --name=appwrite-function-{$functionId} \
            --volume {$tagPathTargetDir}:/tmp:rw \
            --workdir /usr/local/src \
            --env APPWRITE_FUNCTION_ID={$functionId} \
            --env APPWRITE_FUNCTION_TAG={$functionTag} \
            --env APPWRITE_FUNCTION_ENV_NAME={$environment['name']} \
            --env APPWRITE_FUNCTION_ENV_VERSION={$environment['version']} \
            {$environment['image']} \
            sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && {$tag->getAttribute('command', '')}'"
        , null, $stdout, $stderr, $timeout);

        $end = \microtime(true);

        var_dump('stdout', $stdout);
        var_dump('stderr', $stderr);

        Console::info("Code executed in " . ($end - $start) . " seconds with exit code {$exitCode}");

        // Double-check Cleanup
    }

    public function tearDown()
    {
    }
}
