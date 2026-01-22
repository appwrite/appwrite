<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Docker\Compose;
use Appwrite\Docker\Env;
use Appwrite\Utopia\View;
use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Proofs\Token;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Platform\Action;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Install extends Action
{
    private const INSTALL_STEP_DELAY_SECONDS = 2;
    protected string $path = '/usr/src/code/appwrite';
    protected ?string $hostPath = null;

    public static function getName(): string
    {
        return 'install';
    }

    public function __construct()
    {
        $this
            ->desc('Install Appwrite')
            ->param('http-port', '', new Text(4), 'Server HTTP port', true)
            ->param('https-port', '', new Text(4), 'Server HTTPS port', true)
            ->param('organization', 'appwrite', new Text(0), 'Docker Registry organization', true)
            ->param('image', 'appwrite', new Text(0), 'Main appwrite docker image', true)
            ->param('interactive', 'Y', new Text(1), 'Run an interactive session', true)
            ->param('no-start', false, new Boolean(true), 'Run an interactive session', true)
            ->callback($this->action(...));

        $this->hostPath = $this->detectHostPath($this->path);
        $this->ensureHostPathLink();
    }

    public function action(string $httpPort, string $httpsPort, string $organization, string $image, string $interactive, bool $noStart, bool $isUpgrade = false): void
    {
        $config = Config::getParam('variables');
        $defaultHTTPPort = '80';
        $defaultHTTPSPort = '443';
        /** @var array<string, array<string, string>> $vars array where key is variable name and value is variable */
        $vars = [];

        foreach ($config as $category) {
            foreach ($category['variables'] ?? [] as $var) {
                $vars[$var['name']] = $var;
            }
        }

        Console::success('Starting Appwrite installation...');

        $isLocalInstall = !empty(getenv('APPWRITE_INSTALLER_LOCAL'));

        // Create directory with write permissions
        if (!\file_exists(\dirname($this->path))) {
            if (!@\mkdir(\dirname($this->path), 0755, true)) {
                Console::error('Can\'t create directory ' . \dirname($this->path));
                Console::exit(1);
            }
        }

        // Check for existing installation
        $data = @file_get_contents($this->path . '/docker-compose.yml');
        $existingInstallation = $data !== false;

        if ($existingInstallation) {
            $time = \time();
            if (!$isLocalInstall) {
                Console::info('Compose file found, creating backup: docker-compose.yml.' . $time . '.backup');
                file_put_contents($this->path . '/docker-compose.yml.' . $time . '.backup', $data);
            }
            $compose = new Compose($data);
            $appwrite = $compose->getService('appwrite');
            $oldVersion = $appwrite?->getImageVersion();
            try {
                $ports = $compose->getService('traefik')->getPorts();
            } catch (\Throwable $th) {
                $ports = [
                    $defaultHTTPPort => $defaultHTTPPort,
                    $defaultHTTPSPort => $defaultHTTPSPort
                ];
                Console::warning('Traefik not found. Falling back to default ports.');
            }

            if ($oldVersion) {
                foreach ($compose->getServices() as $service) {
                    if (!$service) {
                        continue;
                    }

                    $env = $service->getEnvironment()->list();

                    foreach ($env as $key => $value) {
                        if (is_null($value)) {
                            continue;
                        }

                        $configVar = $vars[$key] ?? [];
                        if (!empty($configVar) && !($configVar['overwrite'] ?? false)) {
                            $vars[$key]['default'] = $value;
                        }
                    }
                }

                $envData = @file_get_contents($this->path . '/.env');

                if ($envData !== false) {
                    if (!$isLocalInstall) {
                        Console::info('Env file found, creating backup: .env.' . $time . '.backup');
                        file_put_contents($this->path . '/.env.' . $time . '.backup', $envData);
                    }
                    $env = new Env($envData);

                    foreach ($env->list() as $key => $value) {
                        if (is_null($value)) {
                            continue;
                        }

                        $configVar = $vars[$key] ?? [];
                        if (!empty($configVar) && !($configVar['overwrite'] ?? false)) {
                            $vars[$key]['default'] = $value;
                        }
                    }
                }

                foreach ($ports as $key => $value) {
                    if ($value === $defaultHTTPPort) {
                        $defaultHTTPPort = $key;
                    }

                    if ($value === $defaultHTTPSPort) {
                        $defaultHTTPSPort = $key;
                    }
                }
            }
        }

        // If interactive and web mode enabled, start web server
        if ($interactive === 'Y' && Console::isInteractive()) {
            Console::success('Starting web installer...');
            Console::info('Open your browser at: http://localhost:8080');
            Console::info('Press Ctrl+C to cancel installation');

            $this->startWebServer($defaultHTTPPort, $defaultHTTPSPort, $organization, $image, $noStart, $vars);
            return;
        }

        // Fall back to CLI mode
        if (empty($httpPort)) {
            $httpPort = Console::confirm('Choose your server HTTP port: (default: ' . $defaultHTTPPort . ')');
            $httpPort = ($httpPort) ? $httpPort : $defaultHTTPPort;
        }

        if (empty($httpsPort)) {
            $httpsPort = Console::confirm('Choose your server HTTPS port: (default: ' . $defaultHTTPSPort . ')');
            $httpsPort = ($httpsPort) ? $httpsPort : $defaultHTTPSPort;
        }

        $password = new Password();
        $token = new Token();
        foreach ($vars as $var) {
            if (!$var['required'] || !Console::isInteractive() || $interactive !== 'Y') {
                continue;
            }

            $value = Console::confirm($var['question'] . ' (default: \'' . $var['default'] . '\')');

            if (!empty($value)) {
                $userInput[$var['name']] = $value;
            }

            if ($var['filter'] === 'domainTarget' && !empty($value) && $value !== 'localhost') {
                Console::warning("\nIf you haven't already done so, set the following record for {$value} on your DNS provider:\n");
                $mask = "%-15.15s %-10.10s %-30.30s\n";
                printf($mask, "Type", "Name", "Value");
                printf($mask, "A or AAAA", "@", "<YOUR PUBLIC IP>");
                Console::warning("\nUse 'AAAA' if you're using an IPv6 address and 'A' if you're using an IPv4 address.\n");
            }
        }

        $input = $this->prepareEnvironmentVariables($userInput, $vars);
        $this->performInstallation($httpPort, $httpsPort, $organization, $image, $input, $noStart, null, null, $isUpgrade);
    }

    private function detectHostPath(string $path): ?string
    {
        if (!is_file('/proc/self/mountinfo')) {
            return null;
        }

        $bestMatch = null;
        $bestLength = 0;

        foreach (@file('/proc/self/mountinfo') as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode(' - ', $line, 2);
            if (count($parts) < 2) {
                continue;
            }

            $left = preg_split('/\s+/', $parts[0]);
            $right = preg_split('/\s+/', $parts[1]);

            if (count($left) < 5 || count($right) < 2) {
                continue;
            }

            $mountPoint = $left[4];
            $root = $left[3];
            $fsType = $right[0];
            $source = $right[1];

            if (!str_starts_with($path, $mountPoint)) {
                continue;
            }

            $mountLength = strlen($mountPoint);
            if ($mountLength < $bestLength) {
                continue;
            }

            $relative = substr($path, $mountLength);
            $relative = ltrim($relative, '/');

            $hostBase = null;

            if ($fsType === 'fakeowner' && str_starts_with($source, '/run/host_mark/')) {
                $share = basename($source);
                $hostBase = '/' . $share . rtrim($root, '/');
            } elseif ($root !== '/' && $root !== '.') {
                $hostBase = $root;
            } elseif ($source !== 'none') {
                $hostBase = $source;
            }

            if (!$hostBase || $hostBase === '.') {
                continue;
            }

            if ($hostBase[0] !== '/') {
                $hostBase = '/' . $hostBase;
            }

            $candidate = rtrim($hostBase, '/');
            if ($relative !== '') {
                $candidate .= '/' . $relative;
            }

            $candidate = preg_replace('#//+#', '/', $candidate);

            $bestMatch = $candidate;
            $bestLength = $mountLength;
        }

        return $bestMatch;
    }

    private function ensureHostPathLink(): void
    {
        if (empty($this->hostPath) || $this->hostPath === $this->path) {
            return;
        }

        if (is_link($this->hostPath)) {
            return;
        }

        if (is_dir($this->hostPath)) {
            @rmdir($this->hostPath);
        }

        $parent = dirname($this->hostPath);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        if (!file_exists($this->hostPath)) {
            @symlink($this->path, $this->hostPath);
        }
    }

    protected function startWebServer(string $defaultHTTPPort, string $defaultHTTPSPort, string $organization, string $image, bool $noStart, array $vars, bool $isUpgrade = false, ?string $lockedDatabase = null): void
    {
        $port = 8080;
        $host = '0.0.0.0';
        $url = "http://localhost:$port";

        // Create a router script for handling requests
        $routerScript = \sys_get_temp_dir() . '/appwrite-installer-router.php';
        $this->createRouterScript($routerScript, $defaultHTTPPort, $defaultHTTPSPort, $organization, $image, $noStart, $vars, $isUpgrade, $lockedDatabase);

        // Start PHP built-in server in background
        $command = \sprintf(
            'php -S %s:%d %s >/dev/null 2>&1 & echo $!',
            $host,
            $port,
            \escapeshellarg($routerScript)
        );

        // Start the server
        $output = [];
        $exitCode = 0;
        \exec($command, $output, $exitCode);
        $pid = isset($output[0]) ? (int) $output[0] : 0;

        \register_shutdown_function(function () use ($routerScript, $pid) {
            if (\file_exists($routerScript)) {
                \unlink($routerScript);
            }
            if ($pid > 0 && \function_exists('posix_kill')) {
                @\posix_kill($pid, SIGTERM);
            }
        });
        \sleep(3);

        // Check if the server actually started
        $handle = @fsockopen('localhost', $port, $errno, $errstr, 1);
        if ($handle === false) {
            Console::exit(1);
        }
        \fclose($handle);

        // Wait for the server process to finish
        while (true) {
            $handle = @fsockopen('localhost', $port, $errno, $errstr, 1);
            if ($handle === false) {
                break;
            }
            \fclose($handle);
            \sleep(1);
        }

        // Cleanup
        if (\file_exists($routerScript)) {
            \unlink($routerScript);
        }
    }

    private function createRouterScript(string $path, string $defaultHTTPPort, string $defaultHTTPSPort, string $organization, string $image, bool $noStart, array $vars, bool $isUpgrade = false, ?string $lockedDatabase = null): void
    {
        $installPhpPath = __FILE__;
        $appViewsPath = __DIR__ . '/../../../../app/views/install';
        $publicPath = __DIR__ . '/../../../../public';
        $vendorPath = __DIR__ . '/../../../../vendor/autoload.php';
        $appwritePath = __DIR__ . '/../../../../app/init.php';

        $script = <<<'PHP'
<?php
require_once '%s';
require_once '%s';

define('INSTALL_PHP_PATH', '%s');
define('APP_VIEWS_PATH', '%s');
define('PUBLIC_PATH', '%s');
define('DEFAULT_HTTP_PORT', '%s');
define('DEFAULT_HTTPS_PORT', '%s');
define('ORGANIZATION', '%s');
define('IMAGE', '%s');
define('NO_START', %s);
define('VARS_JSON', '%s');
define('IS_UPGRADE', %s);
define('LOCKED_DATABASE', %s);
define('INSTALLER_VIEW', 'installer.phtml');
define('INSTALL_LOCK_TTL', 3600);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

function sanitizeInstallId($value): string
{
    if (!is_string($value)) {
        return '';
    }

    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $value);
    if (!is_string($clean)) {
        return '';
    }

    return substr($clean, 0, 64);
}

function progressFilePath(string $installId): string
{
    return sys_get_temp_dir() . '/appwrite-install-' . $installId . '.json';
}

function globalLockPath(): string
{
    return sys_get_temp_dir() . '/appwrite-install-lock.json';
}

function isGlobalLockActive(?array $lock): bool
{
    if (!$lock || !isset($lock['updatedAt'])) {
        return false;
    }

    if (isset($lock['status']) && in_array($lock['status'], ['completed', 'error'], true)) {
        return false;
    }

    if (time() - (int) $lock['updatedAt'] > INSTALL_LOCK_TTL) {
        return false;
    }

    return true;
}

function withGlobalLock(callable $callback)
{
    $path = globalLockPath();
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        return $callback(null, null);
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return $callback(null, null);
    }

    $contents = stream_get_contents($handle);
    $lock = null;
    if ($contents !== false && $contents !== '') {
        $decoded = json_decode($contents, true);
        if (is_array($decoded)) {
            $lock = $decoded;
        }
    }

    $result = $callback($handle, $lock);

    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $result;
}

function reserveGlobalLock(string $installId): string
{
    return (string) withGlobalLock(function ($handle, $lock) use ($installId) {
        if (!$handle) {
            return 'unavailable';
        }
        if (isGlobalLockActive($lock) && ($lock['installId'] ?? '') !== $installId) {
            return 'locked';
        }
        $payload = [
            'installId' => $installId,
            'status' => 'in-progress',
            'updatedAt' => time(),
        ];
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($payload));
        return 'ok';
    });
}

function updateGlobalLock(string $installId, string $status): void
{
    withGlobalLock(function ($handle, $lock) use ($installId, $status) {
        if (!$handle) {
            return;
        }
        if (isGlobalLockActive($lock) && ($lock['installId'] ?? '') !== $installId) {
            return;
        }
        $payload = [
            'installId' => $installId,
            'status' => $status,
            'updatedAt' => time(),
        ];
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($payload));
    });
}

function readProgressFile(string $installId): array
{
    $path = progressFilePath($installId);
    if (!file_exists($path)) {
        return [
            'installId' => $installId,
            'steps' => [],
        ];
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return [
            'installId' => $installId,
            'steps' => [],
        ];
    }

    $data = json_decode($contents, true);
    if (!is_array($data)) {
        return [
            'installId' => $installId,
            'steps' => [],
        ];
    }

    return $data;
}

function writeProgressFile(string $installId, array $payload): void
{
    $data = readProgressFile($installId);
    if (!isset($data['steps']) || !is_array($data['steps'])) {
        $data['steps'] = [];
    }

    if (!empty($payload['step'])) {
        $data['steps'][$payload['step']] = [
            'status' => $payload['status'] ?? 'in-progress',
            'message' => $payload['message'] ?? '',
            'updatedAt' => $payload['updatedAt'] ?? time(),
        ];
    }

    if (!empty($payload['status']) && $payload['status'] === 'error') {
        $data['error'] = $payload['message'] ?? 'Installation failed';
    }

    if (isset($payload['details']) && is_array($payload['details'])) {
        $data['details'][$payload['step']] = $payload['details'];
    }

    if (isset($payload['payload']) && is_array($payload['payload'])) {
        $data['payload'] = $payload['payload'];
        if (!isset($data['startedAt'])) {
            $data['startedAt'] = $payload['updatedAt'] ?? time();
        }
    }

    $data['updatedAt'] = $payload['updatedAt'] ?? time();

    file_put_contents(progressFilePath($installId), json_encode($data));
}

function sendSseEvent(string $event, array $payload): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($payload) . "\n\n";
    @ob_flush();
    @flush();
}

function respondBadRequest(string $message, bool $wantsStream): void
{
    if ($wantsStream) {
        sendSseEvent('error', ['message' => $message, 'step' => 'config-files']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $message]);
    }
    exit;
}

function hashSensitiveValue(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    return hash('sha256', $trimmed);
}

function isValidPort($value): bool
{
    $string = (string) $value;
    if ($string === '' || !preg_match('/^\d+$/', $string)) {
        return false;
    }
    $port = (int) $string;
    return $port >= 1 && $port <= 65535;
}

function isValidEmailAddress(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function isValidAppDomain(string $value): bool
{
    if ($value === 'localhost') {
        return true;
    }
    if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
        return true;
    }
    return filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
}

function isValidAppDomainInput(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }

    $host = $value;
    $port = null;

    if (str_starts_with($value, '[')) {
        if (!preg_match('/^\[(.+)\](?::(\d+))?$/', $value, $matches)) {
            return false;
        }
        $host = $matches[1] ?? '';
        $port = $matches[2] ?? null;
    } else {
        $parts = explode(':', $value);
        if (count($parts) > 2) {
            return false;
        }
        if (count($parts) === 2) {
            [$host, $port] = $parts;
        }
    }

    if ($port !== null && $port !== '' && !isValidPort($port)) {
        return false;
    }

    return isValidAppDomain($host);
}

function isValidDatabaseAdapter(string $value): bool
{
    return in_array($value, ['mongodb', 'mariadb'], true);
}

function sendInstallerHtmlHeaders(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
}

// Serve static files
if ($uri !== '/' && $uri !== '') {
    $requestPath = $uri;
    $publicBase = realpath(PUBLIC_PATH);
    $viewsBase = realpath(APP_VIEWS_PATH);
    $filePath = null;

    if ($publicBase !== false) {
        $candidate = realpath($publicBase . $requestPath);
        if ($candidate !== false && str_starts_with($candidate, $publicBase . DIRECTORY_SEPARATOR)) {
            $filePath = $candidate;
        }
    }

    if (($filePath === null || !is_file($filePath)) && str_starts_with($uri, '/installer/') && $viewsBase !== false) {
        $candidate = realpath($viewsBase . $requestPath);
        if ($candidate !== false && str_starts_with($candidate, $viewsBase . DIRECTORY_SEPARATOR)) {
            $filePath = $candidate;
        }
    }

    if ($filePath && is_file($filePath)) {
        // Determine content type
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
        ];

        $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $uri === '/install/status') {
    header('Content-Type: application/json');

    $installId = sanitizeInstallId($_GET['installId'] ?? '');
    if ($installId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing installId']);
        exit;
    }

    $path = progressFilePath($installId);
    if (!file_exists($path)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Install not found']);
        exit;
    }

    $data = readProgressFile($installId);
    echo json_encode(['success' => true, 'progress' => $data]);
    exit;
}

// Handle POST request (completion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $uri === '/install/complete') {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $installId = sanitizeInstallId($input['installId'] ?? '');

    if ($installId !== '') {
        updateGlobalLock($installId, 'completed');
    }

    echo json_encode(['success' => true]);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    if (function_exists('posix_getpid') && PHP_OS_FAMILY !== 'Windows') {
        $pid = posix_getpid();
        if ($pid) {
            $delay = 3;
            $command = 'sh -c ' . escapeshellarg("sleep {$delay}; kill {$pid} >/dev/null 2>&1");
            @exec($command . ' >/dev/null 2>&1 &');
        }
    }

    exit;
}

// Handle POST request (installation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $uri === '/install') {
    $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
    $acceptsStream = stripos($acceptHeader, 'text/event-stream') !== false;
    $wantsStream = $acceptsStream;

    if ($wantsStream) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
        }
        @ob_implicit_flush(true);
    } else {
        header('Content-Type: application/json');
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    $appDomain = trim((string) ($input['appDomain'] ?? ''));
    if ($appDomain === '' || !isValidAppDomainInput($appDomain)) {
        respondBadRequest('Please enter a valid hostname', $wantsStream);
    }
    $input['appDomain'] = $appDomain;

    $httpPort = $input['httpPort'] ?? '';
    if (!isValidPort($httpPort)) {
        respondBadRequest('Please enter a valid HTTP port (1-65535)', $wantsStream);
    }

    $httpsPort = $input['httpsPort'] ?? '';
    if (!isValidPort($httpsPort)) {
        respondBadRequest('Please enter a valid HTTPS port (1-65535)', $wantsStream);
    }

    $emailCertificates = trim((string) ($input['emailCertificates'] ?? ''));
    if ($emailCertificates === '' || !isValidEmailAddress($emailCertificates)) {
        respondBadRequest('Please enter a valid email address', $wantsStream);
    }
    $input['emailCertificates'] = $emailCertificates;

    $opensslKey = trim((string) ($input['opensslKey'] ?? ''));
    if ($opensslKey === '' || strlen($opensslKey) > 64) {
        respondBadRequest('Secret API key must be 1-64 characters', $wantsStream);
    }
    $input['opensslKey'] = $opensslKey;

    $assistantOpenAIKey = trim((string) ($input['assistantOpenAIKey'] ?? ''));
    $input['assistantOpenAIKey'] = $assistantOpenAIKey;

    if (!LOCKED_DATABASE) {
        $database = strtolower(trim((string) ($input['database'] ?? '')));
        if (!isValidDatabaseAdapter($database)) {
            respondBadRequest('Please select a supported database', $wantsStream);
        }
        $input['database'] = $database;
    }

    $installId = sanitizeInstallId($input['installId'] ?? '');
    if ($installId === '') {
        $installId = bin2hex(random_bytes(8));
    }

    $lockResult = reserveGlobalLock($installId);
    if ($lockResult !== 'ok') {
        if ($lockResult === 'locked') {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Installation already in progress']);
        } else {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'Installer lock unavailable']);
        }
        exit;
    }

    $retryStep = $input['retryStep'] ?? null;
    $allowedRetrySteps = ['docker-compose', 'env-vars', 'docker-containers'];
    if (!is_string($retryStep) || !in_array($retryStep, $allowedRetrySteps, true)) {
        $retryStep = null;
    }

    $existingPath = progressFilePath($installId);
    $existing = null;
    if (file_exists($existingPath)) {
        $existing = readProgressFile($installId);
        if (!empty($existing['steps']) && $retryStep === null) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Installation already started']);
            exit;
        }
    }

    try {
        ignore_user_abort(true);
        require_once INSTALL_PHP_PATH;
        $installer = new \Appwrite\Platform\Tasks\Install();

        if ($wantsStream) {
            sendSseEvent('install-id', ['installId' => $installId]);
        }

        updateGlobalLock($installId, 'in-progress');

        // Prepare user inputs - use locked database if in upgrade mode
        $payloadInput = [
            '_APP_ENV' => 'production',
            '_APP_OPENSSL_KEY_V1' => $input['opensslKey'] ?? '',
            '_APP_DOMAIN' => $input['appDomain'] ?? 'localhost',
            '_APP_DOMAIN_TARGET' => $input['appDomain'] ?? 'localhost',
            '_APP_EMAIL_CERTIFICATES' => $input['emailCertificates'] ?? '',
            '_APP_DB_ADAPTER' => LOCKED_DATABASE ?? ($input['database'] ?? 'mongodb'),
            '_APP_ASSISTANT_OPENAI_API_KEY' => $input['assistantOpenAIKey'] ?? '',
        ];

        if (is_array($existing) && isset($existing['payload']) && is_array($existing['payload'])) {
            $stored = $existing['payload'];
        $fieldsToCompare = [
            'httpPort',
            'httpsPort',
            'database',
            'appDomain',
            'emailCertificates',
        ];
        foreach ($fieldsToCompare as $field) {
            if (isset($stored[$field]) && isset($input[$field]) && (string) $stored[$field] !== (string) $input[$field]) {
                if ($installId !== '') {
                    updateGlobalLock($installId, 'error');
                }
                respondBadRequest('Installation payload mismatch', $wantsStream);
            }
        }

        $sensitiveFields = [
            'opensslKey' => 'opensslKeyHash',
            'assistantOpenAIKey' => 'assistantOpenAIKeyHash',
        ];
        foreach ($sensitiveFields as $field => $hashField) {
            if (!isset($stored[$hashField]) && !isset($stored[$field])) {
                continue;
            }
            $incomingHash = hashSensitiveValue((string) ($input[$field] ?? ''));
            if (isset($stored[$hashField])) {
                if ((string) $stored[$hashField] !== $incomingHash) {
                    if ($installId !== '') {
                        updateGlobalLock($installId, 'error');
                    }
                    respondBadRequest('Installation payload mismatch', $wantsStream);
                }
            } elseif (isset($stored[$field]) && isset($input[$field]) && (string) $stored[$field] !== (string) $input[$field]) {
                if ($installId !== '') {
                    updateGlobalLock($installId, 'error');
                }
                respondBadRequest('Installation payload mismatch', $wantsStream);
            }
        }

            $payloadInput['_APP_DOMAIN'] = $stored['appDomain'] ?? $payloadInput['_APP_DOMAIN'];
            $payloadInput['_APP_DOMAIN_TARGET'] = $stored['appDomain'] ?? $payloadInput['_APP_DOMAIN_TARGET'];
            $payloadInput['_APP_EMAIL_CERTIFICATES'] = $stored['emailCertificates'] ?? $payloadInput['_APP_EMAIL_CERTIFICATES'];
            $payloadInput['_APP_DB_ADAPTER'] = LOCKED_DATABASE ?? ($stored['database'] ?? $payloadInput['_APP_DB_ADAPTER']);
            $input['httpPort'] = $stored['httpPort'] ?? $input['httpPort'] ?? DEFAULT_HTTP_PORT;
            $input['httpsPort'] = $stored['httpsPort'] ?? $input['httpsPort'] ?? DEFAULT_HTTPS_PORT;
        }

        // Use the prepareEnvironmentVariables method to merge with defaults
        $vars = json_decode(VARS_JSON, true);
        $envVars = $installer->prepareEnvironmentVariables($payloadInput, $vars);

        writeProgressFile($installId, [
            'payload' => [
                'httpPort' => $input['httpPort'] ?? DEFAULT_HTTP_PORT,
                'httpsPort' => $input['httpsPort'] ?? DEFAULT_HTTPS_PORT,
                'database' => $input['database'] ?? 'mongodb',
                'appDomain' => $input['appDomain'] ?? 'localhost',
                'emailCertificates' => $input['emailCertificates'] ?? '',
                'opensslKey' => $input['opensslKey'] ?? '',
                'opensslKeyHash' => hashSensitiveValue((string) ($input['opensslKey'] ?? '')),
                'assistantOpenAIKey' => $input['assistantOpenAIKey'] ?? '',
                'assistantOpenAIKeyHash' => hashSensitiveValue((string) ($input['assistantOpenAIKey'] ?? '')),
            ],
            'step' => 'start',
            'status' => 'in-progress',
            'message' => 'Installation started',
            'updatedAt' => time(),
        ]);

        $progress = function (string $step, string $status, string $message, array $details = []) use ($installId, $wantsStream) {
            $payload = [
                'installId' => $installId,
                'step' => $step,
                'status' => $status,
                'message' => $message,
                'updatedAt' => time(),
            ];
            if (!empty($details)) {
                $payload['details'] = $details;
            }
            writeProgressFile($installId, $payload);
            updateGlobalLock($installId, 'in-progress');
            if ($wantsStream) {
                sendSseEvent('progress', $payload);
            }
        };

        // Call performInstallation method
        $installer->performInstallation(
            $input['httpPort'] ?? DEFAULT_HTTP_PORT,
            $input['httpsPort'] ?? DEFAULT_HTTPS_PORT,
            ORGANIZATION,
            IMAGE,
            $envVars,
            NO_START,
            $progress,
            $retryStep,
            IS_UPGRADE
        );

        if ($wantsStream) {
            sendSseEvent('done', ['installId' => $installId, 'success' => true]);
        } else {
            echo json_encode([
                'success' => true,
                'installId' => $installId,
                'message' => 'Installation completed successfully'
            ]);
        }
        updateGlobalLock($installId, 'completed');

    } catch (\Throwable $e) {
        http_response_code(500);
        if ($installId !== '') {
            $details = ['trace' => $e->getTraceAsString()];
            $previous = $e->getPrevious();
            if ($previous instanceof \Throwable && $previous->getMessage() !== '') {
                $details['output'] = $previous->getMessage();
            }
            writeProgressFile($installId, [
                'step' => 'error',
                'status' => 'error',
                'message' => $e->getMessage(),
                'details' => $details,
                'updatedAt' => time(),
            ]);
        }
        if ($installId !== '') {
            updateGlobalLock($installId, 'error');
        }
        if ($wantsStream) {
            $details = ['trace' => $e->getTraceAsString()];
            $previous = $e->getPrevious();
            if ($previous instanceof \Throwable && $previous->getMessage() !== '') {
                $details['output'] = $previous->getMessage();
            }
            sendSseEvent('error', ['message' => $e->getMessage(), 'details' => $details]);
        } else {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    exit;
}

// Serve installer UI
if ($uri === '/' || $uri === '') {
    sendInstallerHtmlHeaders();

    $vars = json_decode(VARS_JSON, true);
    $defaultHttpPort = DEFAULT_HTTP_PORT;
    $defaultHttpsPort = DEFAULT_HTTPS_PORT;
    $isUpgrade = IS_UPGRADE;
    $lockedDatabase = LOCKED_DATABASE;

    include APP_VIEWS_PATH . '/' . INSTALLER_VIEW;
    exit;
}

// 404
http_response_code(404);
echo '404 Not Found';
PHP;

        $varsJson = json_encode($vars, JSON_UNESCAPED_SLASHES);
        $script = sprintf(
            $script,
            $vendorPath,
            $appwritePath,
            $installPhpPath,
            $appViewsPath,
            $publicPath,
            $defaultHTTPPort,
            $defaultHTTPSPort,
            $organization,
            $image,
            var_export($noStart, true),
            str_replace("'", "\\'", $varsJson),
            var_export($isUpgrade, true),
            var_export($lockedDatabase, true)
        );

        file_put_contents($path, $script);
    }

    public function prepareEnvironmentVariables(array $userInput, array $vars): array
    {
        $input = [];
        $password = new Password();
        $token = new Token();

        // Start with all defaults
        foreach ($vars as $var) {
            if (!empty($var['filter']) && $var['filter'] === 'token') {
                $input[$var['name']] = $token->generate();
            } elseif (!empty($var['filter']) && $var['filter'] === 'password') {
                $input[$var['name']] = $password->generate();
            } else {
                $input[$var['name']] = $var['default'];
            }
        }

        // Override with user inputs
        foreach ($userInput as $key => $value) {
            if ($value !== null && $value !== '') {
                $input[$key] = $value;
            }
        }

        // Set database-specific connection details
        $database = $input['_APP_DB_ADAPTER'] ?? 'mongodb';
        if ($database === 'mongodb') {
            $input['_APP_DB_HOST'] = 'mongodb';
            $input['_APP_DB_PORT'] = 27017;
        } elseif ($database === 'mariadb') {
            $input['_APP_DB_HOST'] = 'mariadb';
            $input['_APP_DB_PORT'] = 3306;
        }

        return $input;
    }

    private function reportProgress(?callable $progress, string $step, string $status, string $message, array $details = []): void
    {
        if (!$progress) {
            return;
        }

        try {
            $progress($step, $status, $message, $details);
        } catch (\Throwable $e) {
        }
    }

    private function delayProgress(?callable $progress, string $status): void
    {
        if ($progress && $status !== 'error') {
            sleep(self::INSTALL_STEP_DELAY_SECONDS);
        }
    }

    public function performInstallation(
        string $httpPort,
        string $httpsPort,
        string $organization,
        string $image,
        array $input,
        bool $noStart,
        ?callable $progress = null,
        ?string $resumeFromStep = null,
        bool $isUpgrade = false
    ): void {
        $isCLI = php_sapi_name() === 'cli';
        $useExistingConfig = !empty(getenv('APPWRITE_INSTALLER_LOCAL'))
            && file_exists($this->path . '/docker-compose.yml')
            && file_exists($this->path . '/.env');

        if (getenv('APPWRITE_INSTALLER_LOCAL')) {
            $organization = 'appwrite';
            $image = 'appwrite';
        }

        $templateForCompose = new View(__DIR__ . '/../../../../app/views/install/compose.phtml');
        $templateForEnv = new View(__DIR__ . '/../../../../app/views/install/env.phtml');

        $database = $input['_APP_DB_ADAPTER'] ?? 'mongodb';

        $version = \defined('APP_VERSION_STABLE') ? APP_VERSION_STABLE : 'latest';
        if (getenv('APPWRITE_INSTALLER_LOCAL')) {
            $version = 'local';
        }

        $templateForCompose
            ->setParam('httpPort', $httpPort)
            ->setParam('httpsPort', $httpsPort)
            ->setParam('version', $version)
            ->setParam('organization', $organization)
            ->setParam('image', $image)
            ->setParam('database', $database)
            ->setParam('hostPath', $this->hostPath);

        $templateForEnv->setParam('vars', $input);

        $steps = ['docker-compose', 'env-vars', 'docker-containers'];
        $startIndex = 0;
        if ($resumeFromStep !== null) {
            $resumeIndex = array_search($resumeFromStep, $steps, true);
            if ($resumeIndex !== false) {
                $startIndex = $resumeIndex;
            }
        }

        $currentStep = null;

        $messages = $isUpgrade ? [
            'config-files' => [
                'start' => 'Updating configuration files...',
                'done' => 'Configuration files updated',
            ],
            'docker-compose' => [
                'start' => 'Updating Docker Compose file...',
                'done' => 'Docker Compose file updated',
            ],
            'env-vars' => [
                'start' => 'Updating environment variables...',
                'done' => 'Environment variables updated',
            ],
            'docker-containers' => [
                'start' => 'Restarting Docker containers...',
                'done' => 'Docker containers restarted',
            ],
        ] : [
            'config-files' => [
                'start' => 'Creating configuration files...',
                'done' => 'Configuration files created',
            ],
            'docker-compose' => [
                'start' => 'Generating Docker Compose file...',
                'done' => 'Docker Compose file generated',
            ],
            'env-vars' => [
                'start' => 'Configuring environment variables...',
                'done' => 'Environment variables configured',
            ],
            'docker-containers' => [
                'start' => 'Starting Docker containers...',
                'done' => 'Docker containers started',
            ],
        ];

        try {
            if ($startIndex <= 1) {
                $this->reportProgress($progress, 'config-files', 'in-progress', $messages['config-files']['start']);
                $this->delayProgress($progress, 'in-progress');
            }

            if ($startIndex <= 0) {
                $currentStep = 'docker-compose';
                $this->reportProgress($progress, 'docker-compose', 'in-progress', $messages['docker-compose']['start']);
                $this->delayProgress($progress, 'in-progress');

                if (!$useExistingConfig) {
                    if (!\file_put_contents($this->path . '/docker-compose.yml', $templateForCompose->render(false))) {
                        throw new \Exception('Failed to save Docker Compose file');
                    }
                }

                $this->reportProgress($progress, 'docker-compose', 'completed', $messages['docker-compose']['done']);
            }

            if ($startIndex <= 1) {
                $currentStep = 'env-vars';
                $this->reportProgress($progress, 'env-vars', 'in-progress', $messages['env-vars']['start']);
                $this->delayProgress($progress, 'in-progress');

                if (!$useExistingConfig) {
                    if (!\file_put_contents($this->path . '/.env', $templateForEnv->render(false))) {
                        throw new \Exception('Failed to save environment variables file');
                    }
                }

                $this->reportProgress($progress, 'env-vars', 'completed', $messages['env-vars']['done']);
                $this->reportProgress($progress, 'config-files', 'completed', $messages['config-files']['done']);
            }

            if ($database === 'mongodb' && !$useExistingConfig) {
                $mongoEntrypoint = __DIR__ . '/../../../../mongo-entrypoint.sh';

                if (file_exists($mongoEntrypoint)) {
                    copy($mongoEntrypoint, $this->path . '/mongo-entrypoint.sh');
                }
            }

            if (!$noStart && $startIndex <= 2) {
                $currentStep = 'docker-containers';
                $this->reportProgress($progress, 'docker-containers', 'in-progress', $messages['docker-containers']['start']);
                $this->delayProgress($progress, 'in-progress');
                $envVars = getenv();
                if (!is_array($envVars)) {
                    $envVars = [];
                }
                if (!$useExistingConfig) {
                    foreach ($input as $key => $value) {
                        if ($value === null || $value === '') {
                            continue;
                        }
                        if (!preg_match('/^[A-Z0-9_]+$/', $key)) {
                            throw new \Exception('Invalid environment variable name');
                        }
                        $envVars[$key] = (string) $value;
                    }
                }

                if ($isCLI) {
                    Console::log("Running \"docker compose up -d --remove-orphans --renew-anon-volumes\"");
                }

                $command = [
                    'docker',
                    'compose',
                    '--project-directory',
                    $this->path,
                    'up',
                    '-d',
                    '--remove-orphans',
                    '--renew-anon-volumes'
                ];
                $descriptorSpec = [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];
                $process = \proc_open($command, $descriptorSpec, $pipes, null, $envVars);
                if (!is_resource($process)) {
                    throw new \Exception('Failed to start Docker Compose');
                }
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                if (is_resource($pipes[1])) {
                    fclose($pipes[1]);
                }
                if (is_resource($pipes[2])) {
                    fclose($pipes[2]);
                }
                $exit = \proc_close($process);

                if ($exit !== 0) {
                    $output = trim(($stderr ?: '') . ($stdout ?: ''));
                    $previous = $output !== '' ? new \RuntimeException($output) : null;
                    throw new \RuntimeException('Failed to start containers', 0, $previous);
                }

                $this->reportProgress($progress, 'docker-containers', 'completed', $messages['docker-containers']['done']);

                if ($isCLI) {
                    Console::success('Appwrite installed successfully');
                }
            } else {
                if ($isCLI) {
                    Console::success('Installation files created. Run "docker compose up -d" to start Appwrite');
                }
            }
        } catch (\Throwable $e) {
            if ($currentStep) {
                $details = [
                    'trace' => $e->getTraceAsString()
                ];
                $previous = $e->getPrevious();
                if ($previous instanceof \Throwable && $previous->getMessage() !== '') {
                    $details['output'] = $previous->getMessage();
                }
                $this->reportProgress($progress, $currentStep, 'error', $e->getMessage(), $details);
            }
            throw $e;
        }
    }
}
