<?php

namespace Appwrite\Platform\Installer;

require_once __DIR__ . '/HttpHandler.php';
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

    private const string DEFAULT_IMAGE = 'appwrite-dev';
    public const string DEFAULT_CONTAINER = 'appwrite-installer';
    private const string PATTERN_SERVER_LOG_FILTER = '/]\s+\S+:\d+\s+(Accepted|Closing)/';
    private const string DEV_SERVER_START_PATTERN = '/PHP\s+\d+\.\d+\.\d+\s+Development Server .* started/';

    private State $state;
    private array $paths = [];

    public function run(): void
    {
        $this->initPaths();

        $this->state = new State($this->paths);

        /* launches the install/upgrade entrypoint in Docker. */
        if (PHP_SAPI === 'cli') {
            $this->runCli();
            return;
        }

        $handler = new HttpHandler($this->paths, $this->state);

        $handler->handleRequest();
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
            $this->killInstallerDevServers();
            $this->cleanupWebInstallerFiles();
            exit(0);
        }

        if (isset($opts['docker'])) {
            $this->printInstallerUrl($host, $port);
            $this->startDockerInstaller($opts);
        }

        $this->printInstallerUrl($host, $port);
        $this->startInstallerDevServer($host, $port);
    }

    private function printInstallerUrl(string $host, string $port): void
    {
        $displayHost = $host === self::INSTALLER_WEB_HOST ? 'localhost' : $host;
        $url = "http://$displayHost:$port";
        fwrite(STDOUT, "Open $url" . PHP_EOL);
    }

    private function killInstallerDevServers(): void
    {
        $pattern = 'php -S .*app/views/install';
        $command = 'pkill -f ' . escapeshellarg($pattern) . ' >/dev/null 2>&1';
        exec($command);
    }

    private function startInstallerDevServer(string $host, string $port): void
    {
        $binary = escapeshellcmd(PHP_BINARY);
        $address = escapeshellarg("$host:$port");
        $docroot = escapeshellarg($this->paths['views']);
        $router = escapeshellarg(__FILE__);
        $command = "$binary -S $address -t $docroot $router";

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            fwrite(STDERR, "Failed to start PHP dev server." . PHP_EOL);
            exit(1);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $lastStatus = null;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, null) === false) {
                break;
            }

            foreach ($read as $stream) {
                $line = fgets($stream);
                if ($line === false) {
                    continue;
                }

                if ($stream === $pipes[2] && preg_match(self::DEV_SERVER_START_PATTERN, $line)) {
                    continue;
                }
                if (preg_match(self::PATTERN_SERVER_LOG_FILTER, $line)) {
                    continue;
                }

                $target = ($stream === $pipes[2]) ? STDERR : STDOUT;
                fwrite($target, $line);
            }

            $status = proc_get_status($process);
            $lastStatus = $status;
            if (!$status['running']) {
                break;
            }
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);
        /**
         * Treat exit code 255 or signaled status as a clean shutdown.
         */
        if ($exitCode === 255 || ($lastStatus['signaled'] ?? false)) {
            $exitCode = 0;
        }
        exit($exitCode);
    }

    private function removeDockerInstallerContainer(string $container): void
    {
        $name = escapeshellarg($container);
        exec("docker rm -f $name >/dev/null 2>&1");
    }

    private function cleanupWebInstallerFiles(): void
    {
        $baseDir = null;
        try {
            $cfg = $this->state->buildConfig();
            if ($cfg->isLocal() && !empty($cfg->getHostPath())) {
                $baseDir = rtrim($cfg->getHostPath(), '/') . '/appwrite';
            }
        } catch (\Throwable) {
            // Fall back to cwd
        }

        if ($baseDir === null) {
            $cwd = getcwd();
            if ($cwd === false) {
                return;
            }
            $baseDir = $cwd;
        }

        $filesToRemove = [
            $baseDir . '/.env.web-installer',
            $baseDir . '/docker-compose.web-installer.yml',
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

    private function ensureCorrectTag(string $source, string $target): void
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
        $this->ensureCorrectTag($image, 'appwrite/appwrite:latest');
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
            '--volume', "$volumePath:$volumePath:rw",
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
 * Run server only on direct execution to avoid Swoole exit exception during autoload.
 *
 * @return bool
 */
function shouldRunInstallerServer(): bool
{
    return PHP_SAPI === 'cli-server' || (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__);
}

if (shouldRunInstallerServer()) {
    $server = new Server();
    $server->run();
}
