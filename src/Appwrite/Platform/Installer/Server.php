<?php

namespace Appwrite\Platform\Installer;

use Appwrite\Installer\Secret;
use Appwrite\Platform\Installer\Http\Installer\Error;
use Appwrite\Platform\Installer\Runtime\Config;
use Appwrite\Platform\Installer\Runtime\State;
use Swoole\Http\Server as SwooleServer;
use Swoole\Runtime;
use Utopia\Console\Command;
use Utopia\Console\Console;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Http\Adapter\Swoole\Server as SwooleAdapter;
use Utopia\Http\Files;
use Utopia\Http\Http;
use Utopia\Platform\Service;

class Server
{
    public const int INSTALLER_WEB_PORT = 20080;
    public const string INSTALLER_WEB_HOST = '0.0.0.0';

    // temp files for state and config management!
    public const string INSTALLER_LOCK_FILE = '/tmp/appwrite-install-lock.json';
    public const string INSTALLER_CONFIG_FILE = '/tmp/appwrite-installer-config.json';
    public const string INSTALLER_COMPLETE_FILE = '/tmp/appwrite-installer-complete';

    public const string STEP_ENV_VARS = 'env-vars';
    public const string STEP_CONFIG_FILES = 'config-files';
    public const string STEP_DOCKER_COMPOSE = 'docker-compose';
    public const string STEP_DOCKER_CONTAINERS = 'docker-containers';
    public const string STEP_ACCOUNT_SETUP = 'account-setup';
    public const string STEP_MIGRATION = 'migration';

    public const string STATUS_IN_PROGRESS = 'in-progress';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_ERROR = 'error';

    public const string CSRF_COOKIE = 'appwrite-installer-csrf';

    private static ?Secret $secret = null;

    public const array INSTALLER_CSP = [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self' data:",
        "font-src 'self' data:",
        "connect-src 'self'",
        "base-uri 'none'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ];

    private const string DEFAULT_IMAGE = 'appwrite-dev';
    public const string DEFAULT_CONTAINER = 'appwrite-installer';
    private const string COMPOSE_FILE = 'docker-compose.yml';
    private const string ENV_FILE = '.env';
    private const string LOCAL_COMPOSE_FILE = 'docker-compose.web-installer.yml';
    private const string LOCAL_ENV_FILE = '.env.web-installer';

    private State $state;
    private array $paths = [];

    public function run(): void
    {
        $this->initPaths();

        $this->state = new State();

        if (PHP_SAPI === 'cli') {
            $this->runCli();
            return;
        }
    }

    private function initPaths(): void
    {
        if (!empty($this->paths)) {
            return;
        }

        $root = dirname(__DIR__, 4);
        $this->paths = [
            'public' => $root . '/public',
            'views' => $root . '/app/views/install',
        ];
    }

    private function runCli(): void
    {
        $opts = getopt('', ['upgrade', 'locked-database::', 'docker', 'clean', 'port::', 'ready-file::']);
        $cfg = $this->state->buildConfig([], true);
        $isDocker = isset($opts['docker']);
        if ($isDocker) {
            $cfg->setIsLocal(true);
            if ($cfg->getHostPath() === null) {
                $cwd = getcwd();
                if ($cwd !== false) {
                    $cfg->setHostPath($cwd);
                }
            }
        }
        if (isset($opts['upgrade'])) {
            $cfg->setIsUpgrade(true);
        }
        if (!empty($opts['locked-database'])) {
            $cfg->setLockedDatabase($opts['locked-database']);
        }
        $this->state->applyEnvConfig($cfg);

        $host = self::INSTALLER_WEB_HOST;
        $port = !empty($opts['port']) ? (string) $opts['port'] : (string) self::INSTALLER_WEB_PORT;
        $readyFile = !empty($opts['ready-file']) ? (string) $opts['ready-file'] : null;

        if (isset($opts['clean'])) {
            $this->removeDockerInstallerContainer(self::DEFAULT_CONTAINER);
            $this->cleanupWebInstallerFiles();
            exit(0);
        }

        if (isset($opts['docker'])) {
            $this->startDockerInstaller($opts);
        }

        self::$secret = Secret::fromEnvironment();
        $this->printInstallerSecret();
        $this->printInstallerUrl($host, $port);
        $this->startSwooleServer($host, (int) $port, $readyFile);
    }

    public static function secret(): Secret
    {
        return self::$secret ??= new Secret('');
    }

    private function printInstallerSecret(): void
    {
        fwrite(STDOUT, PHP_EOL);
        fwrite(STDOUT, 'Installer secret: ' . self::secret()->value . PHP_EOL);
        fwrite(STDOUT, 'Provide it as the ' . Secret::HEADER . ' header, or open the URL below.' . PHP_EOL);
        fwrite(STDOUT, PHP_EOL);
    }

    private function printInstallerUrl(string $host, string $port): void
    {
        $displayHost = $host === self::INSTALLER_WEB_HOST ? 'localhost' : $host;
        $url = "http://$displayHost:$port/?secret=" . self::secret()->value;
        fwrite(STDOUT, "Open $url" . PHP_EOL);
    }

    private function startSwooleServer(string $host, int $port, ?string $readyFile = null): void
    {
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $this->state->clearStaleLock();

        // Preload static files into memory
        $files = new Files();
        $files->load($this->paths['views']);

        // Register resources for dependency injection into actions
        $config = $this->state->buildConfig();
        $this->autoDetectUpgrade($config);
        $paths = $this->paths;
        $state = $this->state;

        $adapter = new class ($host, $port, ['worker_num' => 1]) extends SwooleAdapter {
            public function getNativeServer(): SwooleServer
            {
                return $this->server;
            }
        };

        $nativeServer = $adapter->getNativeServer();

        $container = $adapter->resources();
        $container->set('installerState', fn () => $state);
        $container->set('installerConfig', fn () => $config);
        $container->set('installerPaths', fn () => $paths);
        $container->set('swooleServer', fn () => $nativeServer);

        // Register routes via Utopia Platform
        $platform = new Installer();
        $platform->init(Service::TYPE_HTTP);

        // Register error handler directly so Http::error() preserves the '*' group
        $errorHandler = new Error();
        Http::error()
            ->inject('error')
            ->inject('response')
            ->action($errorHandler->action(...));

        $nativeServer->on('start', function () use ($nativeServer, $port, $readyFile) {
            \Swoole\Process::signal(SIGTERM, fn () => $nativeServer->shutdown());
            \Swoole\Process::signal(SIGINT, fn () => $nativeServer->shutdown());

            if ($readyFile !== null) {
                file_put_contents($readyFile, json_encode(['port' => $port, 'pid' => getmypid()]));
            }
        });

        $adapter->onRequest(function (Request $request, Response $response) use ($adapter, $files) {
            // Serve static files from memory
            $uri = $request->getURI();
            if ($files->isFileLoaded($uri)) {
                $response
                    ->setContentType($files->getFileMimeType($uri))
                    ->send($files->getFileContents($uri));
                return;
            }

            $app = new Http($adapter, 'UTC');
            $app->run($request, $response);
        });

        $adapter->start();
    }

    /**
     * Auto-detect upgrade mode by checking for existing config files.
     * Sets isUpgrade, lockedDatabase, and topology on the config when an
     * existing installation is found and these values aren't already set.
     */
    private function autoDetectUpgrade(Config $config): void
    {
        $basePath = $config->isLocal() ? '/usr/src/code' : (getcwd() ?: '.');
        if ($config->isLocal()) {
            $composePath = $basePath . '/' . self::LOCAL_COMPOSE_FILE;
            $envPath = $basePath . '/' . self::LOCAL_ENV_FILE;
        } else {
            $composePath = $basePath . '/' . self::COMPOSE_FILE;
            $envPath = $basePath . '/' . self::ENV_FILE;
        }

        if (!file_exists($composePath) && !file_exists($envPath)) {
            return;
        }

        if (!$config->isUpgrade()) {
            $config->setIsUpgrade(true);
        }

        if ($config->getLockedDatabase() === null) {
            $database = $this->detectDatabaseFromFiles($composePath, $envPath);
            if ($database !== null) {
                $config->setLockedDatabase($database);
            }
        }

        if (!$config->hasTopology()) {
            $topology = $this->detectTopologyFromFiles($composePath);
            if ($topology !== null) {
                $config->setTopology($topology);
            }
        }
    }

    private function detectTopologyFromFiles(string $composePath): ?string
    {
        $composeData = @file_get_contents($composePath);
        if ($composeData === false) {
            return null;
        }

        if (preg_match('/^\s*appwrite-worker:\s*$/m', $composeData) === 1) {
            return 'combined';
        }

        if (preg_match('/^\s*appwrite-worker-functions:\s*$/m', $composeData) === 1) {
            return 'separate';
        }

        return null;
    }

    private function detectDatabaseFromFiles(string $composePath, string $envPath): ?string
    {
        $dbServices = ['mariadb', 'mongodb', 'postgresql'];

        $composeData = @file_get_contents($composePath);
        if ($composeData !== false) {
            if (preg_match_all('/^\s*(?:container_name:\s*appwrite-(\w+)|(\w+):)\s*$/m', $composeData, $matches)) {
                $serviceNames = array_filter(array_merge($matches[1], $matches[2]));
                foreach ($dbServices as $db) {
                    if (in_array($db, $serviceNames, true)) {
                        return $db;
                    }
                }
            }
            foreach ($dbServices as $db) {
                if (preg_match('/^\s*' . preg_quote($db, '/') . ':\s*$/m', $composeData)) {
                    return $db;
                }
            }
        }

        $envData = @file_get_contents($envPath);
        if ($envData !== false) {
            if (preg_match('/^_APP_DB_ADAPTER=(.+)$/m', $envData, $m)) {
                $adapter = trim($m[1], " \t\n\r\"'");
                if (in_array($adapter, $dbServices, true)) {
                    return $adapter;
                }
            }
            if (preg_match('/^_APP_DB_HOST=(.+)$/m', $envData, $m)) {
                $host = trim($m[1], " \t\n\r\"'");
                if (in_array($host, $dbServices, true)) {
                    return $host;
                }
            }
        }

        return null;
    }

    private function removeDockerInstallerContainer(string $container): void
    {
        $stdout = '';
        $stderr = '';
        Console::execute((new Command('docker'))->argument('rm')->flag('-f')->argument($container), '', $stdout, $stderr);
    }

    private function cleanupWebInstallerFiles(): void
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return;
        }

        $filesToRemove = [
            $cwd . '/.env.web-installer',
            $cwd . '/docker-compose.web-installer.yml',
        ];

        foreach ($filesToRemove as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        $tempDir = sys_get_temp_dir();
        @unlink(self::INSTALLER_LOCK_FILE);
        @unlink(self::INSTALLER_CONFIG_FILE);
        foreach ((array) glob($tempDir . '/appwrite-install-*.json') as $file) {
            @unlink($file);
        }
    }

    private function dockerImageExists(string $image): bool
    {
        $stdout = '';
        $stderr = '';
        return Console::execute((new Command('docker'))->argument('image')->argument('inspect')->argument($image), '', $stdout, $stderr) === 0;
    }

    private function buildDockerInstallerImage(string $image): void
    {
        fwrite(STDOUT, "Building Docker image: {$image}\n");
        $status = $this->attach((new Command('docker'))->argument('compose')->argument('build')->argument('appwrite'));
        if ($status !== 0 || !$this->dockerImageExists($image)) {
            fwrite(STDERR, "Failed to build Docker image: $image\n");
            fwrite(STDERR, "Try: docker compose build appwrite\n");
            exit(1);
        }
    }

    private function ensureLocalInstallerTag(string $source, string $target): void
    {
        $stdout = '';
        $stderr = '';
        if (Console::execute((new Command('docker'))->argument('tag')->argument($source)->argument($target), '', $stdout, $stderr) !== 0) {
            fwrite(STDERR, "Failed to tag Docker image {$source} as {$target}\n");
            exit(1);
        }
    }

    private function startDockerInstaller(array $opts): void
    {
        $image = self::DEFAULT_IMAGE;
        $container = self::DEFAULT_CONTAINER;
        if (!$this->dockerImageExists($image)) {
            $this->buildDockerInstallerImage($image);
        }
        $this->ensureLocalInstallerTag($image, 'appwrite/appwrite:local');
        $port = (string)self::INSTALLER_WEB_PORT;
        $entrypoint = isset($opts['upgrade']) ? 'upgrade' : 'install';

        $this->removeDockerInstallerContainer($container);

        $root = realpath(dirname(__DIR__, 4));
        $volumePath = $root !== false ? $root : (getcwd() ?: '.');
        $dockerConfig = $this->state->buildConfig([], false);
        $dockerConfig->setIsLocal(true);
        $dockerConfig->setHostPath($volumePath);
        if (isset($opts['upgrade'])) {
            $dockerConfig->setIsUpgrade(true);
        }
        if (!empty($opts['locked-database'])) {
            $dockerConfig->setLockedDatabase($opts['locked-database']);
        }
        $configJson = json_encode($dockerConfig->toArray(), JSON_UNESCAPED_SLASHES);
        if (!is_string($configJson)) {
            $configJson = '{}';
        }

        $command = (new Command('docker'))
            ->argument('run')
            ->flag('-i')
            ->flag('--rm')
            ->option('--name', $container)
            ->option('--label', 'com.docker.compose.project=appwrite-installer')
            ->option('--label', 'com.docker.compose.service=appwrite-installer')
            ->option('--add-host', 'host.docker.internal:host-gateway')
            ->option('-p', "127.0.0.1:$port:" . self::INSTALLER_WEB_PORT)
            ->option('--volume', '/var/run/docker.sock:/var/run/docker.sock')
            ->option('--volume', "$volumePath:/usr/src/code:rw")
            ->option('--volume', "$volumePath:$volumePath:rw")
            ->option('-e', 'APPWRITE_INSTALLER_CONFIG=' . $configJson)
            ->option('--entrypoint', $entrypoint)
            ->argument($image);

        exit($this->attach($command));
    }

    /**
     * Run a command on this terminal: it reads its input and streams its
     * output as it happens. Its argv reaches the program directly, so nothing
     * passes through a shell.
     */
    private function attach(Command $command): int
    {
        $pipes = [];
        $process = proc_open($command->toArray(), [STDIN, STDOUT, STDERR], $pipes);

        return \is_resource($process) ? proc_close($process) : 1;
    }
}

/**
 * Run server only on direct CLI execution.
 */
function shouldRunInstallerServer(): bool
{
    return PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__);
}

if (shouldRunInstallerServer()) {
    require_once __DIR__ . '/../../../../vendor/autoload.php';
    $server = new Server();
    $server->run();
}
