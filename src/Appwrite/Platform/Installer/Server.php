<?php

namespace Appwrite\Platform\Installer;

class Server
{
    public const int INSTALLER_WEB_PORT = 20080;
    public const int INSTALLER_WEB_PORT_INTERNAL = 20081;

    public static function writeRouterScript(
        string $path,
        string $installPhpPath,
        string $appViewsPath,
        string $publicPath,
        string $vendorPath,
        string $appwritePath,
        string $defaultHTTPPort,
        string $defaultHTTPSPort,
        string $organization,
        string $image,
        bool $noStart,
        array $vars,
        bool $isUpgrade = false,
        ?string $lockedDatabase = null
    ): void {
        $script = self::renderRouterScript(
            $installPhpPath,
            $appViewsPath,
            $publicPath,
            $vendorPath,
            $appwritePath,
            $defaultHTTPPort,
            $defaultHTTPSPort,
            $organization,
            $image,
            $noStart,
            $vars,
            $isUpgrade,
            $lockedDatabase
        );

        if (file_put_contents($path, $script) === false) {
            throw new \RuntimeException('Failed to write installer router script.');
        }
    }

    private static function renderRouterScript(
        string $installPhpPath,
        string $appViewsPath,
        string $publicPath,
        string $vendorPath,
        string $appwritePath,
        string $defaultHTTPPort,
        string $defaultHTTPSPort,
        string $organization,
        string $image,
        bool $noStart,
        array $vars,
        bool $isUpgrade,
        ?string $lockedDatabase
    ): string {
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

    if (function_exists('posix_getpid')) {
        $pid = posix_getpid();
        if ($pid) {
            $delay = 5;
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
        return sprintf(
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
    }
}
