<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Validator\Authorization;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;

require_once __DIR__.'/../init.php';

\cli_set_process_title('Functions V1 Worker');

Console::success(APP_NAME.' functions worker v1 has started');

$environments = Config::getParam('environments');

$warmupStart = \microtime(true);

Co\run(function() use ($environments) {
    Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    
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

$warmupEnd = \microtime(true);
$warmupTime = $warmupEnd - $warmupStart;

Console::success('Finished warmup in '.$warmupTime.' seconds');

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
 * 1. Get event args - DONE
 * 2. Unpackage code in the isolated container - DONE
 * 3. Execute in container with timeout
 *      + messure execution time - DONE
 *      + pass env vars - DONE
 *      + pass one-time api key
 * 4. Update execution status - DONE
 * 5. Update execution stdout & stderr - DONE
 * 6. Trigger audit log - DONE
 * 7. Trigger usage log - DONE
 */

//TODO aviod scheduled execution if delay is bigger than X offest

/**
 * Limit CPU Usage - DONE
 * Limit Memory Usage - DONE
 * Limit Network Usage
 * Limit Storage Usage (//--storage-opt size=120m \)
 * Make sure no access to redis, mariadb, influxdb or other system services
 * Make sure no access to NFS server / storage volumes
 * Access Appwrite REST from internal network for improved performance
 */

/**
 * Get Usage Stats
 *  -> Network (docker stats --no-stream --format="{{.NetIO}}" appwrite)
 *  -> CPU Time - DONE
 *  -> Invoctions (+1) - DONE
 */

class FunctionsV1
{
    public $args = [];

    public $allowed = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $register;

        $projectId = $this->args['projectId'];
        $functionId = $this->args['functionId'];
        $executionId = $this->args['executionId'];
        $trigger = $this->args['trigger'];
        $event = $this->args['event'];
        $payload = (!empty($this->args['payload'])) ? json_encode($this->args['payload']) : '';

        $database = new Database();
        $database->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
        $database->setNamespace('app_'.$projectId);
        $database->setMocks(Config::getParam('collections', []));

        switch ($trigger) {
            case 'event':
                
                $limit = 30;
                $sum = 30;
                $offset = 0;
                $functions = []; /** @var Document[] $functions */

                while ($sum >= $limit) {

                    Authorization::disable();

                    $functions = $database->find([
                        'limit' => $limit,
                        'offset' => $offset,
                        'orderField' => 'name',
                        'orderType' => 'ASC',
                        'orderCast' => 'string',
                        'filters' => [
                            '$collection='.Database::SYSTEM_COLLECTION_FUNCTIONS,
                        ],
                    ]);

                    Authorization::reset();

                    $sum = \count($functions);
                    $offset = $offset + $limit;

                    Console::log('Fetched '.$sum.' functions...');

                    foreach($functions as $function) {
                        $events =  $function->getAttribute('events', []);
                        $tag =  $function->getAttribute('tag', []);

                        Console::success('Itterating function: '.$function->getAttribute('name'));

                        if(!\in_array($event, $events) || empty($tag)) {
                            continue;
                        }

                        Console::success('Triggered function: '.$event);

                        $this->execute('event', $projectId, '', $database, $function, $event, $payload);
                    }
                }
                break;

            case 'schedule':
                # code...
                break;

            case 'http':
                Authorization::disable();
                $function = $database->getDocument($functionId);
                Authorization::reset();

                if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                    throw new Exception('Function not found');
                }

                $this->execute($trigger, $projectId, $executionId, $database, $function);
                break;
            
            default:
                # code...
                break;
        }
    }

    public function execute(
        string $trigger,
        string $projectId,
        string $executionId,
        Database $database,
        Document $function,
        string $event = '',
        string $payload = ''
    )
    {
        global $register;

        $environments = Config::getParam('environments');

        Authorization::disable();
        $tag = $database->getDocument($function->getAttribute('tag', ''));
        Authorization::reset();

        if($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found', 404);
        }

        Authorization::disable();

        $execution = (!empty($executionId)) ? $database->getDocument($executionId) : $database->createDocument([
            '$collection' => Database::SYSTEM_COLLECTION_EXECUTIONS,
            '$permissions' => [
                'read' => [],
                'write' => [],
            ],
            'dateCreated' => time(),
            'functionId' => $function->getId(),
            'trigger' => $trigger, // http / schedule / event
            'status' => 'processing', // waiting / processing / completed / failed
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => '',
            'time' => 0,
        ]);

        if(false === $execution) {
            throw new Exception('Failed to create execution');
        }
        
        Authorization::reset();

        $environment = (isset($environments[$function->getAttribute('env', '')]))
            ? $environments[$function->getAttribute('env', '')]
            : null;

        if(\is_null($environment)) {
            throw new Exception('Environment "'.$function->getAttribute('env', '').' is not supported');
        }

        $vars = \array_merge($function->getAttribute('vars', []), [
            'APPWRITE_FUNCTION_ID' => $function->getId(),
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
            'APPWRITE_FUNCTION_TAG' => $tag->getId(),
            'APPWRITE_FUNCTION_TRIGGER' => $trigger,
            'APPWRITE_FUNCTION_ENV_NAME' => $environment['name'],
            'APPWRITE_FUNCTION_ENV_VERSION' => $environment['version'],
        ]);

        if('event' === $trigger) {
            $vars = \array_merge($vars, [
                'APPWRITE_FUNCTION_EVENT' => $event,
                'APPWRITE_FUNCTION_EVENT_PAYLOAD' => $payload,
            ]);
        }

        \array_walk($vars, function (&$value, $key) {
            $key = $this->filterEnvKey($key);
            $value = \escapeshellarg((empty($value)) ? 'null' : $value);
            $value = "\t\t\t--env {$key}={$value} \\";
        });

        $tagPath = $tag->getAttribute('path', '');
        $tagPathTarget = '/tmp/project-'.$projectId.'/'.$tag->getId().'/code.tar.gz';
        $tagPathTargetDir = \pathinfo($tagPathTarget, PATHINFO_DIRNAME);
        $container = 'appwrite-function-'.$tag->getId();
        $command = \escapeshellcmd($tag->getAttribute('command', ''));

        if(!\is_readable($tagPath)) {
            throw new Exception('Code is not readable: '.$tag->getAttribute('path', ''));
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

        $stdout = '';
        $stderr = '';

        $executionStart = \microtime(true);
        
        $exitCode = Console::execute('docker ps --all --format "name={{.Names}}&status={{.Status}}&labels={{.Labels}}" --filter label=appwrite-type=function'
        , null, $stdout, $stderr, 30);

        $executionEnd = \microtime(true);

        $list = [];
        $stdout = \explode("\n", $stdout);

        \array_map(function($value) use (&$list) {
            $container = [];

            \parse_str($value, $container);

            if(isset($container['name'])) {
                $container = [
                    'name' => $container['name'],
                    'online' => (\substr($container['status'], 0, 2) === 'Up'),
                    'status' => $container['status'],
                    'labels' => $container['labels'],
                ];

                \array_map(function($value) use (&$container) {
                    $value = \explode('=', $value);
                    
                    if(isset($value[0]) && isset($value[1])) {
                        $container[$value[0]] = $value[1];
                    }
                }, \explode(',', $container['labels']));

                $list[$container['name']] = $container;
            }
        }, $stdout);
        
        Console::info("Functions listed in " . ($executionEnd - $executionStart) . " seconds with exit code {$exitCode}");

        if(isset($list[$container]) && !$list[$container]['online']) {
            $stdout = '';
            $stderr = '';
            
            if(Console::execute("docker rm {$container}", null, $stdout, $stderr, 30) !== 0) {
                throw new Exception('Failed to remove offline container: '.$stderr);
            }

            unset($list[$container]);
        }

        if(!isset($list[$container])) { // Create contianer if not ready
            $stdout = '';
            $stderr = '';
    
            $executionStart = \microtime(true);
            
            $exitCode = Console::execute("docker run \
                -d \
                --entrypoint=\"\" \
                --cpus=4 \
                --memory=128m \
                --memory-swap=128m \
                --rm \
                --name={$container} \
                --label appwrite-type=function \
                --label appwrite-created=".\time()." \
                --volume {$tagPathTargetDir}:/tmp:rw \
                --workdir /usr/local/src \
                ".\implode("\n", $vars)."
                {$environment['image']} \
                sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'"
            , null, $stdout, $stderr, 30);

            $executionEnd = \microtime(true);
    
            if($exitCode !== 0) {
                throw new Exception('Failed to create function environment: '.$stderr);
            }
    
            Console::info("Function created in " . ($executionEnd - $executionStart) . " seconds with exit code {$exitCode}");
        }
        else {
            Console::info('Container is ready to run');
        }
        
        $stdout = '';
        $stderr = '';

        $executionStart = \microtime(true);
        
        $exitCode = Console::execute("docker exec \
        ".\implode("\n", $vars)."
        {$container} \
        {$command}"
        , null, $stdout, $stderr, $function->getAttribute('timeout', (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)));

        $executionEnd = \microtime(true);
        $executionTime = ($executionEnd - $executionStart);
        $functionStatus = ($exitCode === 0) ? 'completed' : 'failed';

        Console::info("Function executed in " . ($executionEnd - $executionStart) . " seconds with exit code {$exitCode}");

        Authorization::disable();
        
        $execution = $database->updateDocument(array_merge($execution->getArrayCopy(), [
            'tagId' => $tag->getId(),
            'status' => $functionStatus,
            'exitCode' => $exitCode,
            'stdout' => \mb_substr($stdout, -4000), // log last 4000 chars output
            'stderr' => \mb_substr($stderr, -4000), // log last 4000 chars output
            'time' => $executionTime,
        ]));
        
        Authorization::reset();

        if (false === $function) {
            throw new Exception('Failed saving execution to DB', 500);
        }

        $usage = $register->get('queue-usage');

        $usage
            ->setParam('projectId', $projectId)
            ->setParam('functionId', $function->getId())
            ->setParam('functionExecution', 1)
            ->setParam('functionStatus', $functionStatus)
            ->setParam('functionExecutionTime', $executionTime * 1000) // ms
            ->setParam('networkRequestSize', 0)
            ->setParam('networkResponseSize', 0)
        ;

        $usage->trigger();

        Console::success(count($list).' running containers counted');

        $max = (int) App::getEnv('_APP_FUNCTIONS_CONTAINERS');

        if(\count($list) > $max) {
            Console::info('Starting containers cleanup');

            $sorted = [];
            
            foreach($list as $env) {
                $sorted[] = [
                    'name' => $env['name'],
                    'created' => (int)$env['appwrite-created']
                ];
            }

            \usort($sorted, function ($item1, $item2) {
                return $item1['created'] <=> $item2['created'];
            });

            while(\count($sorted) > $max) {
                $first = \array_shift($sorted);
                $stdout = '';
                $stderr = '';

                if(Console::execute("docker stop {$first['name']}", null, $stdout, $stderr, 30) !== 0) {
                    Console::error('Failed to remove container: '.$stderr);
                }

                Console::info('Removed container: '.$first['name']);
            }
        }
    }

    public function filterEnvKey(string $string): string
    {
        if(empty($this->allowed)) {
            $this->allowed = array_fill_keys(\str_split('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_'), true);
        }

        $string     = \str_split($string);
        $output     = '';

        foreach ($string as $char) {
            if(\array_key_exists($char, $this->allowed)) {
                $output .= $char;
            }
        }

        return $output;
    }

    public function tearDown()
    {
    }
}
