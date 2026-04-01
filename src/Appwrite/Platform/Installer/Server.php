<?php

namespace Appwrite\Platform\Installer;

use Appwrite\Platform\Installer\Http\Installer\Error;
use Appwrite\Platform\Installer\Runtime\Config;
use Appwrite\Platform\Installer\Runtime\State;
use Swoole\Http\Server as SwooleServer;
use Swoole\Runtime;
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
    public const string STEP_SSL_CERTIFICATE = 'ssl-certificate';

    public const string STATUS_IN_PROGRESS = 'in-progress';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_ERROR = 'error';

    public const string CSRF_COOKIE = 'appwrite-installer-csrf';

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

    private State $state;
    private array $paths = [];

    public function run(): void
    {
        $this->initPaths();

        $this->state = new State($this->paths);

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
            $this->printInstallerUrl($host, $port);
            $this->startDockerInstaller($opts);
        }

        $this->printInstallerUrl($host, $port);
        $this->startSwooleServer($host, (int) $port, $readyFile);
    }

    private function printInstallerUrl(string $host, string $port): void
    {
        $displayHost = $host === self::INSTALLER_WEB_HOST ? 'localhost' : $host;
        $url = "http://$displayHost:$port";
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

        Http::setResource('installerState', fn () => $state);
        Http::setResource('installerConfig', fn () => $config);
        Http::setResource('installerPaths', fn () => $paths);

        // Register routes via Utopia Platform
        $platform = new Installer();
        $platform->init(Service::TYPE_HTTP);

        // Register error handler directly so Http::error() preserves the '*' group
        $errorHandler = new Error();
        Http::error()
            ->inject('error')
            ->inject('response')
            ->action($errorHandler->action(...));

        $adapter = new class ($host, $port, ['worker_num' => 1]) extends SwooleAdapter {
            public function getNativeServer(): SwooleServer
            {
                return $this->server;
            }
        };

        $nativeServer = $adapter->getNativeServer();

        Http::setResource('swooleServer', fn () => $nativeServer);

        $nativeServer->on('start', function () use ($nativeServer, $port, $readyFile) {
            \Swoole\Process::signal(SIGTERM, fn () => $nativeServer->shutdown());
            \Swoole\Process::signal(SIGINT, fn () => $nativeServer->shutdown());

            if ($readyFile !== null) {
                file_put_contents($readyFile, json_encode(['port' => $port, 'pid' => getmypid()]));
            }
        });

        $adapter->onRequest(function (Request $request, Response $response) use ($files) {
            // Serve static files from memory
            $uri = $request->getURI();
            if ($files->isFileLoaded($uri)) {
                $response
                    ->setContentType($files->getFileMimeType($uri))
                    ->send($files->getFileContents($uri));
                return;
            }

            $app = new Http('UTC');
            $app->run($request, $response);
        });

        $adapter->start();
    }

    /**
     * Auto-detect upgrade mode by checking for existing config files.
     * Sets isUpgrade and lockedDatabase on the config when an existing
     * installation is found and these values aren't already set.
     */
    private function autoDetectUpgrade(Config $config): void
    {
        if ($config->isUpgrade()) {
            return;
        }

        $basePath = $config->isLocal() ? '/usr/src/code' : (getcwd() ?: '.');
        $composePath = $basePath . '/docker-compose.yml';
        $envPath = $basePath . '/.env';

        if (!file_exists($composePath) && !file_exists($envPath)) {
            return;
        }

        $config->setIsUpgrade(true);

        if ($config->getLockedDatabase() !== null) {
            return;
        }

        $database = $this->detectDatabaseFromFiles($composePath, $envPath);
        if ($database !== null) {
            $config->setLockedDatabase($database);
        }
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
        $name = escapeshellarg($container);
        exec("docker rm -f $name >/dev/null 2>&1");
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
        $result = 1;
        exec("docker image inspect " . escapeshellarg($image) . " >/dev/null 2>&1", $output, $result);
        return $result === 0;
    }

    private function buildDockerInstallerImage(string $image): void
    {
        fwrite(STDOUT, "Building Docker image: {$image}\n");
        $buildCommand = 'docker compose build appwrite';
        passthru($buildCommand, $status);
        if ($status !== 0 || !$this->dockerImageExists($image)) {
            fwrite(STDERR, "Failed to build Docker image: $image\n");
            fwrite(STDERR, "Try: docker compose build appwrite\n");
            exit(1);
        }
    }

    private function ensureLocalInstallerTag(string $source, string $target): void
    {
        $sourceArg = escapeshellarg($source);
        $targetArg = escapeshellarg($target);
        exec("docker tag {$sourceArg} {$targetArg}", $tagOutput, $tagStatus);
        if ($tagStatus !== 0) {
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

        $args = [
            'docker',
            'run',
            '-i',
            '--rm',
            '--name', $container,
            '-p', "127.0.0.1:$port:" . self::INSTALLER_WEB_PORT,
            '--volume', '/var/run/docker.sock:/var/run/docker.sock',
            '--volume', "$volumePath:/usr/src/code:rw",
        ];
        $args[] = '-e';
        $args[] = 'APPWRITE_INSTALLER_CONFIG=' . $configJson;
        $args[] = '--entrypoint=' . $entrypoint;
        $args[] = $image;

        $command = implode(' ', array_map(escapeshellarg(...), $args));
        passthru($command, $status);
        exit($status);
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
