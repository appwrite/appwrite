<?php

require_once dirname(__DIR__, 3) . '/src/Appwrite/Platform/Installer/Server.php';

use Appwrite\Platform\Installer\Server as InstallerServer;

const INSTALLER_DEFAULT_HOST = 'localhost';
const INSTALLER_DEFAULT_IMAGE = 'appwrite-dev';
const INSTALLER_DEFAULT_CONTAINER = 'appwrite-installer';
const DEV_SERVER_START_PATTERN = '/PHP\s+\d+\.\d+\.\d+\s+Development Server .* started/';
const INSTALLER_CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

function sendInstallerHtmlHeaders(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Security-Policy: ' . INSTALLER_CSP);
}

function printInstallerUrl(bool $isMock, string $host, string $port): void
{
    $url = "http://{$host}:{$port}/installer.phtml";
    $message = $isMock ? "Mock mode enabled: {$url}" : "Open {$url}";
    fwrite(STDOUT, $message . PHP_EOL);
}

function killInstallerDevServers(): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        return;
    }

    $pattern = 'php -S .*app/views/install';
    $command = 'pkill -f ' . escapeshellarg($pattern) . ' >/dev/null 2>&1';
    \exec($command);
}

#[NoReturn]
function startInstallerDevServer(string $host, string $port): void
{
    $binary = escapeshellcmd(PHP_BINARY);
    $address = escapeshellarg("$host:$port");
    $docroot = escapeshellarg(__DIR__);
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

            if ($stream === $pipes[2] && preg_match(DEV_SERVER_START_PATTERN, $line)) {
                continue;
            }

            $target = ($stream === $pipes[2]) ? STDERR : STDOUT;
            fwrite($target, $line);
        }

        $status = proc_get_status($process);
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
    exit($exitCode);
}

function removeDockerInstallerContainer(string $container): void
{
    $name = escapeshellarg($container);
    \exec("docker rm -f {$name} >/dev/null 2>&1");
}

function dockerImageExists(string $image): bool
{
    $name = escapeshellarg($image);
    $result = 1;
    \exec("docker image inspect $name >/dev/null 2>&1", $output, $result);
    return $result === 0;
}

function buildDockerInstallerImage(string $image): void
{
    fwrite(STDOUT, "Building Docker image: {$image}\n");
    $buildCommand = 'docker compose build appwrite';
    passthru($buildCommand, $status);
    if ((int)$status !== 0 || !dockerImageExists($image)) {
        fwrite(STDERR, "Failed to build Docker image: {$image}\n");
        fwrite(STDERR, "Try: docker compose build appwrite\n");
        exit(1);
    }
}

function ensureLocalInstallerTag(string $source, string $target): void
{
    $sourceArg = escapeshellarg($source);
    $targetArg = escapeshellarg($target);
    $exists = 1;
    \exec("docker image inspect {$targetArg} >/dev/null 2>&1", $output, $exists);
    if ($exists === 0) {
        return;
    }
    \exec("docker tag {$sourceArg} {$targetArg}", $tagOutput, $tagStatus);
    if ($tagStatus !== 0) {
        fwrite(STDERR, "Failed to tag Docker image {$source} as {$target}\n");
        exit(1);
    }
}

#[NoReturn]
function startDockerInstaller(array $opts): void
{
    $container = $opts['container'] ?? INSTALLER_DEFAULT_CONTAINER;
    $image = INSTALLER_DEFAULT_IMAGE;
    if (!dockerImageExists($image)) {
        buildDockerInstallerImage($image);
    }
    ensureLocalInstallerTag($image, 'appwrite/appwrite:local');
    $port = (string) InstallerServer::INSTALLER_WEB_PORT;
    $entrypoint = isset($opts['upgrade']) ? 'upgrade' : 'install';

    removeDockerInstallerContainer($container);

    $cwd = getcwd() ?: '.';
    $volumePath = $cwd;
    $envArgs = [];
    $envArgs[] = ['-e', 'APPWRITE_INSTALLER_LOCAL=1'];
    if (isset($opts['mock'])) {
        $envArgs[] = ['-e', 'APPWRITE_INSTALLER_MOCK=1'];
    }
    if (!empty($opts['locked-database'])) {
        $envArgs[] = ['-e', '_APP_DB_ADAPTER=' . $opts['locked-database']];
    }

    $args = [
        'docker',
        'run',
        '-it',
        '--rm',
        '--name', $container,
        '-p', "$port:" . InstallerServer::INSTALLER_WEB_PORT_INTERNAL,
        '--volume', '/var/run/docker.sock:/var/run/docker.sock',
        '--volume', "$volumePath:/usr/src/code/appwrite:rw",
    ];
    foreach ($envArgs as $envPair) {
        $args[] = $envPair[0];
        $args[] = $envPair[1];
    }
    $args[] = '--entrypoint=' . $entrypoint;
    $args[] = $image;

    $escaped = array_map('escapeshellarg', $args);
    $command = implode(' ', $escaped);
    passthru($command, $status);
    exit($status);
}

function handleInstallerRequest(): bool
{
    $path = $_SERVER['REQUEST_URI'] ?? '';
    $requestPath = parse_url($path, PHP_URL_PATH) ?? '';
    if ($requestPath === '' || $requestPath === '/') {
        $requestPath = '/installer.phtml';
    }
    $basePath = realpath(__DIR__);
    if ($basePath === false) {
        return false;
    }
    $candidate = $basePath . $requestPath;
    $filePath = realpath($candidate);
    if ($filePath === false || !str_starts_with($filePath, $basePath . DIRECTORY_SEPARATOR)) {
        return false;
    }

    $upgradeEnv = getenv('APPWRITE_INSTALLER_UPGRADE');
    if ($upgradeEnv !== false) {
        $isUpgrade = $upgradeEnv === '1' || strtolower($upgradeEnv) === 'true';
    }

    $lockedDbEnv = getenv('APPWRITE_INSTALLER_LOCKED_DATABASE');
    if ($lockedDbEnv !== false && $lockedDbEnv !== '') {
        $lockedDatabase = $lockedDbEnv;
    }

    if (is_file($filePath)) {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        if (in_array($extension, ['html', 'phtml', 'php'], true)) {
            sendInstallerHtmlHeaders();
            include $filePath;
            return true;
        }
        return false;
    }

    return false;
}

if (PHP_SAPI === 'cli') {
    $opts = getopt('', ['host::', 'mock', 'upgrade', 'locked-database::', 'docker', 'container::', 'clean']);
    $host = $opts['host'] ?? INSTALLER_DEFAULT_HOST;
    $port = (string) InstallerServer::INSTALLER_WEB_PORT;
    $isMock = isset($opts['mock']);
    if (isset($opts['clean'])) {
        removeDockerInstallerContainer($opts['container'] ?? INSTALLER_DEFAULT_CONTAINER);
        killInstallerDevServers();
        exit(0);
    }
    if (isset($opts['docker'])) {
        printInstallerUrl($isMock, $host, $port);
        startDockerInstaller($opts);
    }
    if ($isMock) {
        putenv('APPWRITE_INSTALLER_MOCK=1');
    }
    if (isset($opts['upgrade'])) {
        putenv('APPWRITE_INSTALLER_UPGRADE=1');
    }
    if (!empty($opts['locked-database'])) {
        putenv('APPWRITE_INSTALLER_LOCKED_DATABASE=' . $opts['locked-database']);
    }

    printInstallerUrl($isMock, $host, $port);
    flush();
    startInstallerDevServer($host, $port);
}

return handleInstallerRequest();
