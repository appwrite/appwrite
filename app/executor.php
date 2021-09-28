<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Validator\UID;
use Appwrite\Event\Event;
use Appwrite\Utopia\Response\Model\Execution;
use Utopia\App;
use Utopia\Swoole\Request;
use Appwrite\Utopia\Response;
use Utopia\CLI\Console;
use Swoole\Process;
use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Exception\Timeout as TimeoutException;
use Utopia\Config\Config;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;
use Cron\CronExpression;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Swoole\Coroutine as Co;
use Utopia\Orchestration\Adapter\DockerCLI;

require_once __DIR__ . '/workers.php';

$dockerUser = App::getEnv('DOCKERHUB_PULL_USERNAME', null);
$dockerPass = App::getEnv('DOCKERHUB_PULL_PASSWORD', null);
$dockerEmail = App::getEnv('DOCKERHUB_PULL_EMAIL', null);
$orchestration = new Orchestration(new DockerCLI($dockerUser, $dockerPass));

$runtimes = Config::getParam('runtimes');

Swoole\Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

// Warmup: make sure images are ready to run fast 🚀
Co\run(function () use ($runtimes, $orchestration) {
    foreach ($runtimes as $runtime) {
        go(function () use ($runtime, $orchestration) {
            Console::info('Warming up ' . $runtime['name'] . ' ' . $runtime['version'] . ' environment...');

            $response = $orchestration->pull($runtime['image']);

            if ($response) {
                Console::success("Successfully Warmed up {$runtime['name']} {$runtime['version']}!");
            } else {
                Console::error("Failed to Warmup {$runtime['name']} {$runtime['version']}!");
            }
        });
    }
});

/**
 * List function servers
 */
$executionStart = \microtime(true);

$response = $orchestration->list(['label' => 'appwrite-type=function']);

$activeFunctions = new Swoole\Table(1024);
$activeFunctions->column('id', Swoole\Table::TYPE_STRING, 512);
$activeFunctions->column('name', Swoole\Table::TYPE_STRING, 512);
$activeFunctions->column('status', Swoole\Table::TYPE_STRING, 512);
$activeFunctions->column('key', Swoole\Table::TYPE_STRING, 4096);
$activeFunctions->create();


foreach ($response as $value) {
    $activeFunctions->set($value->getName(), [
        'id' => $value->getId(),
        'name' => $value->getName(),
        'status' => $value->getStatus(),
        'private-key' => ''
    ]);
}

$executionEnd = \microtime(true);

Console::info(count($activeFunctions) . ' functions listed in ' . ($executionEnd - $executionStart) . ' seconds');

App::post('/v1/execute') // Define Route
    ->inject('request')
    ->param('trigger', '', new Text(1024))
    ->param('projectId', '', new Text(1024))
    ->param('executionId', '', new Text(1024), '', true)
    ->param('functionId', '', new Text(1024))
    ->param('event', '', new Text(1024), '', true)
    ->param('eventData', '', new Text(1024), '', true)
    ->param('data', '', new Text(1024), '', true)
    ->param('webhooks', [], new ArrayList(new JSON()), [], true)
    ->param('userId', '', new Text(1024), '', true)
    ->param('jwt', '', new Text(1024), '', true)
    ->inject('response')
    ->action(
        function ($trigger, $projectId, $executionId, $functionId, $event, $eventData, $data, $webhooks, $userId, $jwt, $request, $response) {
            global $register;

            $db = $register->get('dbPool')->get();
            $cache = $register->get('redisPool')->get();

            // Create new Database Instance
            $database = new Database();
            $database->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
            $database->setNamespace('app_' . $projectId);
            $database->setMocks(Config::getParam('collections', []));

            try {
                $data = execute($trigger, $projectId, $executionId, $functionId, $database, $event, $eventData, $data, $webhooks, $userId, $jwt);
                $response->json($data);
            } catch (Exception $e) {
                $response
                    ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                    ->addHeader('Expires', '0')
                    ->addHeader('Pragma', 'no-cache')
                    ->json(['error' => $e->getMessage()]);
            } finally {
                $register->get('dbPool')->put($db);
                $register->get('redisPool')->put($cache);
            }
        }
    );


// Cleanup Endpoints used internally by appwrite when a function or tag gets deleted to also clean up their containers
App::post('/v1/cleanup/function')
    ->param('functionId', '', new UID())
    ->inject('response')
    ->inject('projectDB')
    ->inject('projectID')
    ->action(function ($functionId, $response, $projectDB, $projectID) {
        /** @var string $functionId */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var string $projectID */

        global $orchestration;

        try {
            Authorization::disable();
            $function = $projectDB->getDocument($functionId);
            Authorization::reset();

            if (\is_null($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                throw new Exception('Function not found', 404);
            }

            Authorization::disable();
            $results = $projectDB->getCollection([
                'limit' => 999,
                'offset' => 0,
                'orderType' => 'ASC',
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_TAGS,
                    'functionId=' . $functionId,
                ],
            ]);
            Authorization::reset();

            // If amount is 0 then we simply return true
            if (count($results) === 0) {
                return $response->json(['success' => true]);
            }

            // Delete the containers of all tags
            foreach ($results as $tag) {
                try {
                    $orchestration->remove('appwrite-function-' . $tag['$id'], true);
                    Console::info('Removed container for tag ' . $tag['$id']);
                } catch (Exception $e) {
                    // Do nothing, we don't care that much if it fails
                }
            }

            return $response->json(['success' => true]);
        } catch (Exception $e) {
            Console::error($e->getMessage());
            return $response->json(['error' => $e->getMessage()]);
        }
    });

App::post('/v1/cleanup/tag')
    ->param('tagId', '', new UID(), 'Tag unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('projectID')
    ->action(function ($tagId, $response, $projectDB, $projectID) {
        /** @var string $tagId */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var string $projectID */

        global $orchestration;

        try {
            Authorization::disable();
            $tag = $projectDB->getDocument($tagId);
            Authorization::reset();

            if (\is_null($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
                throw new Exception('Tag not found', 404);
            }

            try {
                $orchestration->remove('appwrite-function-' . $tag['$id'], true);
                Console::info('Removed container for tag ' . $tag['$id']);
            } catch (Exception $e) {
                // Do nothing, we don't care that much if it fails
            }
        } catch (Exception $e) {
            Console::error($e->getMessage());
            return $response->json(['error' => $e->getMessage()]);
        }

        return $response->json(['success' => true]);
    });

App::post('/v1/tag')
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('tagId', '', new UID(), 'Tag unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('projectID')
    ->action(function ($functionId, $tagId, $response, $projectDB, $projectID) {
        Authorization::disable();
        $project = $projectDB->getDocument($projectID);
        $function = $projectDB->getDocument($functionId);
        $tag = $projectDB->getDocument($tagId);
        Authorization::reset();

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
            throw new Exception('Tag not found', 404);
        }

        $schedule = $function->getAttribute('schedule', '');
        $cron = (empty($function->getAttribute('tag')) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (empty($function->getAttribute('tag')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : null;

        Authorization::disable();
        $function = $projectDB->updateDocument(array_merge($function->getArrayCopy(), [
            'tag' => $tag->getId(),
            'scheduleNext' => $next
        ]));
        Authorization::reset();

        // Build Code
        go(function () use ($projectDB, $projectID, $function, $tagId, $functionId) {
            // Build Code
            $tag = runBuildStage($tagId, $function, $projectID, $projectDB);

            // Deploy Runtime Server
            createRuntimeServer($functionId, $projectID, $tag, $projectDB);
        });

        if ($next) { // Init first schedule
            ResqueScheduler::enqueueAt($next, 'v1-functions', 'FunctionsV1', [
                'projectId' => $projectID,
                'webhooks' => $project->getAttribute('webhooks', []),
                'functionId' => $function->getId(),
                'executionId' => null,
                'trigger' => 'schedule',
            ]);  // Async task reschedule
        }

        if (false === $function) {
            throw new Exception('Failed saving function to DB', 500);
        }

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::get('/v1/healthz')
    ->inject('request')
    ->inject('response')
    ->action(
        function ($request, $response) {
            $response
                ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->addHeader('Expires', '0')
                ->addHeader('Pragma', 'no-cache')
                ->json(['status' => 'online']);
        }
    );

function runBuildStage(string $tagID, Document $function, string $projectID, Database $database): Document
{
    global $runtimes;
    global $orchestration;

    $buildStdout = '';
    $buildStderr = '';

    // Check if tag is already built
    Authorization::disable();
    $tag = $database->getDocument($tagID);
    Authorization::reset();

    try {
        // If we already have a built package ready there is no need to rebuild.
        if ($tag->getAttribute('status') === 'ready' && \file_exists($tag->getAttribute('builtPath'))) {
            return $tag;
        }

        // Update Tag Status
        Authorization::disable();
        $tag = $database->updateDocument(array_merge($tag->getArrayCopy(), [
            'status' => 'building'
        ]));
        Authorization::reset();

        // Check if runtime is active
        $runtime = (isset($runtimes[$function->getAttribute('runtime', '')]))
            ? $runtimes[$function->getAttribute('runtime', '')]
            : null;

        if ($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found', 404);
        }

        if (\is_null($runtime)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        // Grab Tag Files
        $tagPath = $tag->getAttribute('path', '');
        $tagPathTarget = '/tmp/project-' . $projectID . '/' . $tag->getId() . '/code.tar.gz';
        $tagPathTargetDir = \pathinfo($tagPathTarget, PATHINFO_DIRNAME);
        $container = 'build-stage-' . $tag->getId();

        if (!\is_readable($tagPath)) {
            throw new Exception('Code is not readable: ' . $tag->getAttribute('path', ''));
        }

        if (!\file_exists($tagPathTargetDir)) {
            if (!\mkdir($tagPathTargetDir, 0755, true)) {
                throw new Exception('Can\'t create directory ' . $tagPathTargetDir);
            }
        }

        if (!\file_exists($tagPathTarget)) {
            if (!\copy($tagPath, $tagPathTarget)) {
                throw new Exception('Can\'t create temporary code file ' . $tagPathTarget);
            }
        }

        $vars = \array_merge($function->getAttribute('vars', []), [
            'APPWRITE_FUNCTION_ID' => $function->getId(),
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
            'APPWRITE_FUNCTION_TAG' => $tag->getId(),
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
            'APPWRITE_FUNCTION_PROJECT_ID' => $projectID,
            'APPWRITE_ENTRYPOINT_NAME' => $tag->getAttribute('entrypoint')
        ]);

        $buildStart = \microtime(true);
        $buildTime = \time();

        $orchestration->setCpus(App::getEnv('_APP_FUNCTIONS_CPUS', '1'));
        $orchestration->setMemory(App::getEnv('_APP_FUNCTIONS_MEMORY', '256'));
        $orchestration->setSwap(App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', '256'));

        foreach ($vars as &$value) {
            $value = strval($value);
        }

        $id = $orchestration->run(
            image: $runtime['base'],
            name: $container,
            vars: $vars,
            workdir: '/usr/code',
            labels: [
                'appwrite-type' => 'function',
                'appwrite-created' => strval($buildTime),
                'appwrite-runtime' => $function->getAttribute('runtime', ''),
            ],
            command: [
                'tail',
                '-f',
                '/dev/null'
            ],
            hostname: $container,
            mountFolder: $tagPathTargetDir,
            volumes: [
                '/tmp/project-' . $projectID . '/' . $tag->getId() . '/builtCode' . ':/usr/builtCode:rw'
            ]
        );

        $untarStdout = '';
        $untarStderr = '';

        $untarSuccess = $orchestration->execute(
            name: $container,
            command: [
                'sh',
                '-c',
                'mkdir -p /usr/code && cp /tmp/code.tar.gz /usr/code.tar.gz && cd /usr && tar -zxf /usr/code.tar.gz -C /usr/code && rm /usr/code.tar.gz'
            ],
            stdout: $untarStdout,
            stderr: $untarStderr,
            timeout: 60
        );

        if (!$untarSuccess) {
            throw new Exception('Failed to extract tar: ' . $untarStderr);
        }

        // Build Code / Install Dependencies
        $buildSuccess = $orchestration->execute(
            name: $container,
            command: ['sh', '-c', 'cd /usr/local/src && ./build.sh'],
            stdout: $buildStdout,
            stderr: $buildStderr,
            timeout: 600 //TODO: Make this configurable
        );

        if (!$buildSuccess) {
            throw new Exception('Failed to build dependencies: ' . $buildStderr);
        }

        // Repackage Code and Save.
        $compressStdout = '';
        $compressStderr = '';

        $builtCodePath = '/tmp/project-' . $projectID . '/' . $tag->getId() . '/builtCode/code.tar.gz';

        $compressSuccess = $orchestration->execute(
            name: $container,
            command: [
                'tar', '-C', '/usr/code', '-czvf', '/usr/builtCode/code.tar.gz', './'
            ],
            stdout: $compressStdout,
            stderr: $compressStderr,
            timeout: 60
        );

        if (!$compressSuccess) {
            throw new Exception('Failed to compress built code: ' . $compressStderr);
        }

        // Remove Container
        $orchestration->remove($id, true);

        // Check if the build was successful by checking if file exists
        if (!\file_exists($builtCodePath)) {
            throw new Exception('Something went wrong during the build process.');
        }

        // Upload new code
        $device = Storage::getDevice('functions');

        $path = $device->getPath(\uniqid() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));

        if (!\file_exists(\dirname($path))) { // Checks if directory path to file exists
            if (!@\mkdir(\dirname($path), 0755, true)) {
                throw new Exception('Can\'t create directory: ' . \dirname($path));
            }
        }

        if (!\rename($builtCodePath, $path)) {
            throw new Exception('Failed moving file', 500);
        }

        // Update tag with built code attribute
        Authorization::disable();
        $tag = $database->updateDocument(array_merge($tag->getArrayCopy(), [
            'builtPath' => $path,
            'status' => 'ready',
            'buildStdout' => $buildStdout,
            'buildStderr' => $buildStderr
        ]));
        Authorization::enable();

        $buildEnd = \microtime(true);

        Console::info('Tag Built in ' . ($buildEnd - $buildStart) . ' seconds');
    } catch (Exception $e) {
        Console::error('Tag build failed: ' . $e->getMessage());
        Authorization::disable();
        $tag = $database->updateDocument(array_merge($tag->getArrayCopy(), [
            'status' => 'failed',
            'buildStdout' => $buildStdout,
            'buildStderr' => $buildStderr,
        ]));
        Authorization::enable();
    }

    return $tag;
}

function createRuntimeServer(string $functionId, string $projectId, Document $tag, Database $database)
{
    global $register;
    global $orchestration;
    global $runtimes;
    global $activeFunctions;

    // Grab Tag Document
    Authorization::disable();
    $function = $database->getDocument($functionId);
    Authorization::reset();

    // Check if function isn't already created
    $functions = $orchestration->list(['label' => 'appwrite-type=function', 'name' => 'appwrite-function-' . $tag->getId()]);

    if (\count($functions) > 0) {
        return;
    }

    // Generate random secret key
    $secret = \bin2hex(\random_bytes(16));

    // Check if runtime is active
    $runtime = (isset($runtimes[$function->getAttribute('runtime', '')]))
        ? $runtimes[$function->getAttribute('runtime', '')]
        : null;

    if ($tag->getAttribute('functionId') !== $function->getId()) {
        throw new Exception('Tag not found', 404);
    }

    if (\is_null($runtime)) {
        throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
    }

    // Process environment variables
    $vars = \array_merge($function->getAttribute('vars', []), [
        'APPWRITE_FUNCTION_ID' => $function->getId(),
        'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
        'APPWRITE_FUNCTION_TAG' => $tag->getId(),
        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
        'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
        'APPWRITE_INTERNAL_RUNTIME_KEY' => $secret,
    ]);

    $container = 'appwrite-function-' . $tag->getId();

    if ($activeFunctions->exists($container) && !(\substr($activeFunctions->get($container)['status'], 0, 2) === 'Up')) { // Remove container if not online
        // If container is online then stop and remove it
        try {
            $orchestration->remove($container);
        } catch (Exception $e) {
            Console::warning('Failed to remove container: ' . $e->getMessage());
        }

        $activeFunctions->del($container);
    }

    // Check if tag is built yet.
    if ($tag->getAttribute('status') !== 'ready') {
        throw new Exception('Tag is not built yet', 500);
    }

    // Grab Tag Files
    $tagPath = $tag->getAttribute('builtPath', '');

    $tagPathTarget = '/tmp/project-' . $projectId . '/' . $tag->getId() . '/builtCode/code.tar.gz';
    $tagPathTargetDir = \pathinfo($tagPathTarget, PATHINFO_DIRNAME);
    $container = 'appwrite-function-' . $tag->getId();

    if (!\is_readable($tagPath)) {
        throw new Exception('Code is not readable: ' . $tagPath);
    }

    if (!\file_exists($tagPathTargetDir)) {
        if (!\mkdir($tagPathTargetDir, 0755, true)) {
            throw new Exception('Can\'t create directory ' . $tagPathTargetDir);
        }
    }

    if (!\file_exists($tagPathTarget)) {
        if (!\copy($tagPath, $tagPathTarget)) {
            throw new Exception('Can\'t create temporary code file ' . $tagPathTarget);
        }
    }

    /**
     * Limit CPU Usage - DONE
     * Limit Memory Usage - DONE
     * Limit Network Usage
     * Limit Storage Usage (//--storage-opt size=120m \)
     * Make sure no access to redis, mariadb, influxdb or other system services
     * Make sure no access to NFS server / storage volumes
     * Access Appwrite REST from internal network for improved performance
     */
    if (!$activeFunctions->exists($container)) { // Create contianer if not ready
        $executionStart = \microtime(true);
        $executionTime = \time();

        $orchestration->setCpus(App::getEnv('_APP_FUNCTIONS_CPUS', '1'));
        $orchestration->setMemory(App::getEnv('_APP_FUNCTIONS_MEMORY', '256'));
        $orchestration->setSwap(App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', '256'));
        foreach ($vars as &$value) {
            $value = strval($value);
        }

        $id = $orchestration->run(
            image: $runtime['image'],
            name: $container,
            vars: $vars,
            labels: [
                'appwrite-type' => 'function',
                'appwrite-created' => strval($executionTime),
                'appwrite-runtime' => $function->getAttribute('runtime', ''),
            ],
            hostname: $container,
            mountFolder: $tagPathTargetDir,
        );

        // Add to network
        $orchestration->networkConnect($container, 'appwrite_runtimes');

        $executionEnd = \microtime(true);

        $activeFunctions->set($container, [
            'id' => $id,
            'name' => $container,
            'status' => 'Up ' . \round($executionEnd - $executionStart, 2) . 's',
            'key' => $secret,
        ]);

        Console::info('Runtime Server created in ' . ($executionEnd - $executionStart) . ' seconds');
    } else {
        Console::info('Runtime server is ready to run');
    }
};

function execute(string $trigger, string $projectId, string $executionId, string $functionId, Database $database, string $event = '', string $eventData = '', string $data = '', array $webhooks = [], string $userId = '', string $jwt = ''): array
{
    Console::info('Executing function: ' . $functionId);

    global $activeFunctions;
    global $runtimes;

    // Grab Tag Document
    Authorization::disable();
    $function = $database->getDocument($functionId);
    $tag = $database->getDocument($function->getAttribute('tag', ''));
    Authorization::reset();

    if ($tag->getAttribute('functionId') !== $function->getId()) {
        throw new Exception('Tag not found', 404);
    }

    Authorization::disable();
    // Grab execution document if exists
    // It it doesn't exist, create a new one.
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

    if (false === $execution || ($execution instanceof Document && $execution->isEmpty())) {
        throw new Exception('Failed to create or read execution');
    }

    Authorization::reset();

    if ($tag->getAttribute('status') == 'building') {
        Console::error('Execution Failed. Reason: Code was still being built.');
        Authorization::disable();
        $execution = $database->updateDocument(array_merge($execution->getArrayCopy(), [
            'tagId' => $tag->getId(),
            'status' => 'failed',
            'exitCode' => 1,
            'stderr' => 'Tag is still being built.', // log last 4000 chars output
            'time' => 0
        ]));
        Authorization::reset();
        throw new Exception('Tag is still being built.');
    }

    // Check if runtime is active
    $runtime = (isset($runtimes[$function->getAttribute('runtime', '')]))
        ? $runtimes[$function->getAttribute('runtime', '')]
        : null;

    if (\is_null($runtime)) {
        throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
    }

    // Process environment variables
    $vars = \array_merge($function->getAttribute('vars', []), [
        'APPWRITE_FUNCTION_ID' => $function->getId(),
        'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
        'APPWRITE_FUNCTION_TAG' => $tag->getId(),
        'APPWRITE_FUNCTION_TRIGGER' => $trigger,
        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
        'APPWRITE_FUNCTION_EVENT' => $event,
        'APPWRITE_FUNCTION_EVENT_DATA' => $eventData,
        'APPWRITE_FUNCTION_DATA' => $data,
        'APPWRITE_FUNCTION_USER_ID' => $userId,
        'APPWRITE_FUNCTION_JWT' => $jwt,
        'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
    ]);

    $container = 'appwrite-function-' . $tag->getId();

    try {
        if ($tag->getAttribute('status') !== 'ready') {
            runBuildStage($tag->getId(), $function, $projectId, $database);
            sleep(1);
        }
    } catch (Exception $e) {
        Console::error('Something went wrong building the code. ' . $e->getMessage());
        $execution = $database->updateDocument(array_merge($execution->getArrayCopy(), [
            'tagId' => $tag->getId(),
            'status' => 'failed',
            'exitCode' => 1,
            'stderr' => \utf8_encode(\mb_substr($e->getMessage(), -4000)), // log last 4000 chars output
            'time' => 0
        ]));
    }

    try {
        if (!$activeFunctions->exists($container)) { // Create contianer if not ready
            createRuntimeServer($functionId, $projectId, $tag, $database);
        } else if ($activeFunctions->get($container)['status'] === 'Down') {
            sleep(1);
        } else {
            Console::info('Container is ready to run');
        }
    } catch (Exception $e) {
        Console::error('Something went wrong building the runtime server. ' . $e->getMessage());
        Authorization::disable();
        $execution = $database->updateDocument(array_merge($execution->getArrayCopy(), [
            'tagId' => $tag->getId(),
            'status' => 'failed',
            'exitCode' => 1,
            'stderr' => \utf8_encode(\mb_substr($e->getMessage(), -4000)), // log last 4000 chars output
            'time' => 0
        ]));
        Authorization::enable();
        return [
            'status' => 'failed',
            'response' => \utf8_encode(\mb_substr($e->getMessage(), -4000)), // log last 4000 chars output
            'time' => 0
        ];
    }

    $internalFunction = $activeFunctions->get('appwrite-function-' . $tag->getId());
    $key = $internalFunction['key'];

    // Process environment variables
    $vars = \array_merge($function->getAttribute('vars', []), [
        'APPWRITE_FUNCTION_ID' => $function->getId(),
        'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
        'APPWRITE_FUNCTION_TAG' => $tag->getId(),
        'APPWRITE_FUNCTION_TRIGGER' => $trigger,
        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
        'APPWRITE_FUNCTION_EVENT' => $event,
        'APPWRITE_FUNCTION_EVENT_DATA' => $eventData,
        'APPWRITE_FUNCTION_DATA' => $data,
        'APPWRITE_FUNCTION_USER_ID' => $userId,
        'APPWRITE_FUNCTION_JWT' => $jwt,
        'APPWRITE_FUNCTION_PROJECT_ID' => $projectId
    ]);

    $stdout = '';
    $stderr = '';

    $executionStart = \microtime(true);

    $exitCode = 0;

    $errNo = -1;
    $attempts = 0;
    $max = 5;

    $executorResponse = '';

    // cURL request to runtime
    do {
        $attempts++;
        $ch = \curl_init();

        $body = \json_encode([
            'path' => '/usr/code',
            'file' => $tag->getAttribute('entrypoint', ''),
            'env' => $vars,
            'payload' => $data,
            'timeout' => $function->getAttribute('timeout', (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900))
        ]);

        \curl_setopt($ch, CURLOPT_URL, "http://" . $container . ":3000/");
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, $function->getAttribute('timeout', (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)));
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . \strlen($body),
            'x-internal-challenge: ' . $key
        ]);

        $executorResponse = \curl_exec($ch);

        $error = \curl_error($ch);

        $errNo = \curl_errno($ch);

        \curl_close($ch);
        if ($errNo != CURLE_COULDNT_CONNECT) {
            break;
        }

        sleep(1);
    } while ($attempts < $max);

    if ($attempts >= 5) {
        $stderr = 'Failed to connect to executor runtime after 5 attempts.';
        $exitCode = 124;
    }

    // If timeout error
    if ($errNo == CURLE_OPERATION_TIMEDOUT || $errNo == 110) {
        $exitCode = 124;
    }

    // 110 is the Swoole error code for timeout, see: https://www.swoole.co.uk/docs/swoole-error-code
    if ($errNo !== 0 && $errNo != CURLE_COULDNT_CONNECT && $errNo != CURLE_OPERATION_TIMEDOUT && $errNo != 110) {
        Console::error('A internal curl error has occoured within the executor! Error Msg: '. $error);
        throw new Exception('Curl error: ' . $error, 500);
    }

    if (!empty($executorResponse)) {
        $executionData = json_decode($executorResponse, true);
    }

    if (isset($executionData['code'])) {
        $exitCode = $executionData['code'];
    }

    if ($exitCode === 500) {
        $stderr = $executionData['message'];
    } else if ($exitCode === 0) {
        $stdout = $executorResponse;
    }

    $executionEnd = \microtime(true);
    $executionTime = ($executionEnd - $executionStart);
    $functionStatus = ($exitCode === 0) ? 'completed' : 'failed';

    Console::info('Function executed in ' . ($executionEnd - $executionStart) . ' seconds, status: ' . $functionStatus);

    Authorization::disable();

    $execution = $database->updateDocument(array_merge($execution->getArrayCopy(), [
        'tagId' => $tag->getId(),
        'status' => $functionStatus,
        'exitCode' => $exitCode,
        'stdout' => \utf8_encode(\mb_substr($stdout, -4000)), // log last 4000 chars output
        'stderr' => \utf8_encode(\mb_substr($stderr, -4000)), // log last 4000 chars output
        'time' => $executionTime
    ]));

    Authorization::reset();

    if (false === $function) {
        throw new Exception('Failed saving execution to DB', 500);
    }

    $executionModel = new Execution();
    $executionUpdate = new Event('v1-webhooks', 'WebhooksV1');

    $executionUpdate
        ->setParam('projectId', $projectId)
        ->setParam('userId', $userId)
        ->setParam('webhooks', $webhooks)
        ->setParam('event', 'functions.executions.update')
        ->setParam('eventData', $execution->getArrayCopy(array_keys($executionModel->getRules())));

    $executionUpdate->trigger();

    $usage = new Event('v1-usage', 'UsageV1');

    $usage
        ->setParam('projectId', $projectId)
        ->setParam('functionId', $function->getId())
        ->setParam('functionExecution', 1)
        ->setParam('functionStatus', $functionStatus)
        ->setParam('functionExecutionTime', $executionTime * 1000) // ms
        ->setParam('networkRequestSize', 0)
        ->setParam('networkResponseSize', 0);

    if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
        $usage->trigger();
    }

    return [
        'status' => $functionStatus,
        'response' => $stdout,
        'time' => $executionTime
    ];
}

App::setMode(App::MODE_TYPE_PRODUCTION); // Define Mode

$http = new Server("0.0.0.0", 8080);

$http->on('start', function ($http) {
    Process::signal(SIGINT, function () use ($http) {
        handleShutdown();
        $http->shutdown();
    });

    Process::signal(SIGQUIT, function () use ($http) {
        handleShutdown();
        $http->shutdown();
    });

    Process::signal(SIGKILL, function () use ($http) {
        handleShutdown();
        $http->shutdown();
    });

    Process::signal(SIGTERM, function () use ($http) {
        handleShutdown();
        $http->shutdown();
    });
});

$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
    global $register;

    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);
    $app = new App('UTC');

    $db = $register->get('dbPool')->get();
    $redis = $register->get('redisPool')->get();

    App::setResource('db', function () use (&$db) {
        return $db;
    });

    App::setResource('cache', function () use (&$redis) {
        return $redis;
    });

    $projectId = $request->getHeader('x-appwrite-project', '');

    Storage::setDevice('functions', new Local(APP_STORAGE_FUNCTIONS . '/app-' . $projectId));

    // Check environment variable key
    $secretKey = $request->getHeader('x-appwrite-executor-key', '');

    if (empty($secretKey)) {
        $swooleResponse->status(401);
        return $swooleResponse->end('401: Authentication Error');
    }

    if ($secretKey !== App::getEnv('_APP_EXECUTOR_SECRET', '')) {
        $swooleResponse->status(401);
        return $swooleResponse->end('401: Authentication Error');
    }

    App::setResource('projectDB', function ($db, $cache) use ($projectId) {
        $projectDB = new Database();
        $projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
        $projectDB->setNamespace('app_' . $projectId);
        $projectDB->setMocks(Config::getParam('collections', []));

        return $projectDB;
    }, ['db', 'cache']);

    App::error(function ($error, $utopia, $request, $response) {
        /** @var Exception $error */
        /** @var Utopia\App $utopia */
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */

        if ($error instanceof PDOException) {
            throw $error;
        }

        $route = $utopia->match($request);

        Console::error('[Error] Timestamp: ' . date('c', time()));

        if ($route) {
            Console::error('[Error] Method: ' . $route->getMethod());
        }

        Console::error('[Error] Type: ' . get_class($error));
        Console::error('[Error] Message: ' . $error->getMessage());
        Console::error('[Error] File: ' . $error->getFile());
        Console::error('[Error] Line: ' . $error->getLine());

        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        $code = $error->getCode();
        $message = $error->getMessage();

        $output = ((App::isDevelopment())) ? [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTrace(),
            'version' => $version,
        ] : [
            'message' => $message,
            'code' => $code,
            'version' => $version,
        ];

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode($code);

        $response->dynamic(
            new Document($output),
            $utopia->isDevelopment() ? Response::MODEL_ERROR_DEV : Response::MODEL_ERROR
        );
    }, ['error', 'utopia', 'request', 'response']);

    App::setResource('projectID', function () use ($projectId) {
        return $projectId;
    });

    try {
        $app->run($request, $response);
    } catch (Exception $e) {
        Console::error('There\'s a problem with ' . $request->getURI());
        $swooleResponse->end('500: Server Error');
    }
});

$http->start();

function handleShutdown()
{
    Console::info('Cleaning up containers before shutdown...');

    // Remove all containers.
    global $orchestration;

    $functionsToRemove = $orchestration->list(['label' => 'appwrite-type=function']);

    foreach ($functionsToRemove as $container) {
        try {
            $orchestration->remove($container->getId(), true);
            Console::info('Removed container ' . $container->getName());
        } catch (Exception $e) {
            Console::error('Failed to remove container: ' . $container->getName());
        }
    }
}
