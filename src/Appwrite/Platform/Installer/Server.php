<?php

namespace Appwrite\Platform\Installer;

require_once __DIR__ . '/Runtime/State.php';
require_once __DIR__ . '/Runtime/Config.php';

use Appwrite\Platform\Installer\Runtime\State;

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

        // Load autoloader for Swoole/Utopia classes
        require_once $this->paths['vendor'];

        // Load module classes after autoloader is available
        require_once __DIR__ . '/Module.php';
        require_once __DIR__ . '/Services/Http.php';
        require_once __DIR__ . '/Http/Installer/View.php';
        require_once __DIR__ . '/Http/Installer/Status.php';
        require_once __DIR__ . '/Http/Installer/Validate.php';
        require_once __DIR__ . '/Http/Installer/Complete.php';
        require_once __DIR__ . '/Http/Installer/Install.php';
        require_once __DIR__ . '/Http/Installer/Error.php';

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
            'init' => $root . '/app/init.php',
            'views' => $root . '/app/views/install',
            'vendor' => $root . '/vendor/autoload.php',
            'installPhp' => $root . '/src/Appwrite/Platform/Tasks/Install.php',
        ];
    }

    private function runCli(): void
    {
        $opts = getopt('', ['upgrade', 'locked-database::', 'docker', 'clean']);
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
        $port = (string) self::INSTALLER_WEB_PORT;

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
        $this->startSwooleServer($host, (int) $port);
    }

    private function printInstallerUrl(string $host, string $port): void
    {
        $displayHost = $host === self::INSTALLER_WEB_HOST ? 'localhost' : $host;
        $url = "http://$displayHost:$port";
        fwrite(STDOUT, "Open $url" . PHP_EOL);
    }

    private function startSwooleServer(string $host, int $port): void
    {
        $server = new \Swoole\Http\Server($host, $port);
        $server->set([
            'worker_num' => 1,
        ]);

        // Preload static files into memory
        $files = new \Utopia\Http\Files();
        $files->load($this->paths['views']);

        // Register resources for dependency injection into actions
        $config = $this->state->buildConfig();
        $paths = $this->paths;
        $state = $this->state;

        \Utopia\Http\Http::setResource('installerState', fn () => $state);
        \Utopia\Http\Http::setResource('installerConfig', fn () => $config);
        \Utopia\Http\Http::setResource('installerPaths', fn () => $paths);
        \Utopia\Http\Http::setResource('swooleServer', fn () => $server);

        // Register routes via module structure
        $module = new Module();
        $services = $module->getServicesByType(\Utopia\Platform\Service::TYPE_HTTP);

        foreach ($services as $service) {
            foreach ($service->getActions() as $action) {
                $type = $action->getType();

                switch ($type) {
                    case \Utopia\Platform\Action::TYPE_ERROR:
                        $hook = \Utopia\Http\Http::error();
                        break;
                    default:
                        $httpMethod = $action->getHttpMethod();
                        $httpPath = $action->getHttpPath();
                        $hook = \Utopia\Http\Http::addRoute($httpMethod, $httpPath);
                        break;
                }

                $hook->desc($action->getDesc() ?? '');

                foreach ($action->getOptions() as $key => $option) {
                    if ($option['type'] === 'injection') {
                        $hook->inject($option['name']);
                    }
                }

                $hook->action($action->getCallback());
            }
        }

        $server->on('request', function (\Swoole\Http\Request $swooleRequest, \Swoole\Http\Response $swooleResponse) use ($files) {
            \Utopia\Http\Http::setResource('swooleRequest', fn () => $swooleRequest);
            \Utopia\Http\Http::setResource('swooleResponse', fn () => $swooleResponse);

            $request = new \Utopia\Http\Adapter\Swoole\Request($swooleRequest);
            $response = new \Utopia\Http\Adapter\Swoole\Response($swooleResponse);

            // Serve static files from memory
            $uri = $request->getURI();
            if ($files->isFileLoaded($uri)) {
                $response
                    ->setContentType($files->getFileMimeType($uri))
                    ->send($files->getFileContents($uri));
                return;
            }

            $app = new \Utopia\Http\Http('UTC');
            $app->run($request, $response);
        });

        // Handle Ctrl+C gracefully
        \Swoole\Process::signal(2, function () use ($server) {
            $server->shutdown();
        });

        $server->start();
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
    $server = new Server();
    $server->run();
}
