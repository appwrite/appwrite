<?php

namespace Appwrite\Platform\Installer;

use Appwrite\Platform\Installer\Runtime\Config;
use Appwrite\Platform\Installer\Runtime\State;

class HttpHandler
{
    private const string CSRF_COOKIE = 'appwrite-installer-csrf';

    private const array INSTALLER_CSP = [
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

    private const int HTTP_CONFLICT = 409;
    private const int HTTP_NOT_FOUND = 404;
    private const int HTTP_BAD_REQUEST = 400;
    private const int HTTP_SERVICE_UNAVAILABLE = 503;
    private const int HTTP_INTERNAL_SERVER_ERROR = 500;

    private const int SHUTDOWN_DELAY_SECONDS = 5;
    private const int SSE_KEEPALIVE_DELAY_MICROSECONDS = 500000;

    private array $paths;
    private State $state;
    private Config $config;

    public function __construct(array $paths, State $state)
    {
        $this->paths = $paths;
        $this->state = $state;
        $this->config = $state->buildConfig();
    }

    public function handleRequest(): void
    {
        $uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
        if ($uri === '') {
            $uri = '/';
        }

        if ($this->handleStaticFiles($uri)) {
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $handled = match (true) {
            $method === 'GET' && $uri === '/install/status' => $this->handleStatusRequest($uri),
            $method === 'POST' && $uri === '/install/validate' => $this->handleValidateRequest($uri),
            $method === 'POST' && $uri === '/install/complete' => $this->handleCompleteRequest($uri),
            $method === 'POST' && $uri === '/install' => $this->handleInstallRequest($uri),
            $uri === '/' => $this->handleInstallerView($uri),
            default => false,
        };

        if ($handled) {
            return;
        }

        http_response_code(self::HTTP_NOT_FOUND);
        echo '404 Not Found';
    }

    private function handleStaticFiles(string $uri): bool
    {
        if ($uri === '/' || $uri === '') {
            return false;
        }

        $appViewsPath = realpath($this->paths['views']);
        $publicPath = realpath($this->paths['public']);

        if (!$appViewsPath || !$publicPath) {
            return false;
        }

        $filePath = null;
        $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
        if ($extension) {
            $candidate = realpath($appViewsPath . $uri);
            if ($candidate && str_starts_with($candidate, $appViewsPath . DIRECTORY_SEPARATOR)) {
                $filePath = $candidate;
            }
            if (!$filePath) {
                $candidate = realpath($publicPath . $uri);
                if ($candidate && str_starts_with($candidate, $publicPath . DIRECTORY_SEPARATOR)) {
                    $filePath = $candidate;
                }
            }
        }

        if ($filePath && is_file($filePath)) {
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

            if (!isset($mimeTypes[$extension])) {
                return false;
            }

            $contentType = $mimeTypes[$extension];
            header('Content-Type: ' . $contentType);
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            return true;
        }

        return false;
    }

    private function handleStatusRequest(string $uri): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' || $uri !== '/install/status') {
            return false;
        }

        header('Content-Type: application/json');

        $installId = $this->state->sanitizeInstallId($_GET['installId'] ?? '');
        if ($installId === '') {
            http_response_code(self::HTTP_BAD_REQUEST);
            echo json_encode(['success' => false, 'message' => 'Missing installId']);
            return true;
        }

        $path = $this->state->progressFilePath($installId);
        if (!file_exists($path)) {
            http_response_code(self::HTTP_NOT_FOUND);
            echo json_encode(['success' => false, 'message' => 'Install not found']);
            return true;
        }

        $data = $this->state->readProgressFile($installId);
        if ($this->hasPayload($data)) {
            unset($data['payload']['opensslKey'], $data['payload']['assistantOpenAIKey']);
        }
        echo json_encode(['success' => true, 'progress' => $data]);
        return true;
    }

    private function handleValidateRequest(string $uri): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $uri !== '/install/validate') {
            return false;
        }

        header('Content-Type: application/json');
        $this->validateCsrf(false);
        echo json_encode(['success' => true]);
        return true;
    }

    private function handleCompleteRequest(string $uri): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $uri !== '/install/complete') {
            return false;
        }

        header('Content-Type: application/json');
        $this->validateCsrf(false);

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
        $installId = $this->state->sanitizeInstallId($input['installId'] ?? '');
        $sessionId = is_string($input['sessionId'] ?? null) ? $input['sessionId'] : null;
        $sessionSecret = is_string($input['sessionSecret'] ?? null) ? $input['sessionSecret'] : null;
        $sessionExpire = is_string($input['sessionExpire'] ?? null) ? $input['sessionExpire'] : null;

        if ($installId !== '') {
            $this->state->updateGlobalLock($installId, Server::STATUS_COMPLETED);
        }

        @touch(Server::INSTALLER_COMPLETE_FILE);

        if ($sessionSecret) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $sameSite = $isHttps ? 'None' : 'Lax';
            $expires = 0;
            if ($sessionExpire) {
                $timestamp = strtotime($sessionExpire);
                if ($timestamp !== false) {
                    $expires = $timestamp;
                }
            }
            $cookieOptions = [
                'expires' => $expires,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => $sameSite,
            ];
            setcookie('a_session_console', $sessionSecret, $cookieOptions);
            setcookie('a_session_console_legacy', $sessionSecret, $cookieOptions);
            if ($sessionId) {
                header('X-Appwrite-Session: ' . $sessionId);
            }
        }

        @unlink(Server::INSTALLER_CONFIG_FILE);

        echo json_encode(['success' => true]);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        if (function_exists('posix_getpid')) {
            $pid = posix_getpid();
            if ($pid) {
                $command = 'sh -c ' . escapeshellarg("sleep " . self::SHUTDOWN_DELAY_SECONDS . "; kill $pid >/dev/null 2>&1");
                @exec($command . ' >/dev/null 2>&1 &');
            }
        }

        return true;
    }

    private function handleInstallRequest(string $uri): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $uri !== '/install') {
            return false;
        }

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

            echo "event: ping\n";
            echo "data: {\"time\":" . time() . "}\n\n";
            @ob_flush();
            @flush();
        } else {
            header('Content-Type: application/json');
        }

        $this->validateCsrf($wantsStream);

        $rawBody = file_get_contents('php://input');
        $input = json_decode($rawBody, true);

        if (!is_array($input)) {
            http_response_code(self::HTTP_BAD_REQUEST);
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            return true;
        }

        $appDomain = trim((string) ($input['appDomain'] ?? ''));
        if ($appDomain === '' || !$this->state->isValidAppDomainInput($appDomain)) {
            $this->respondBadRequest('Please enter a valid hostname', $wantsStream);
        }
        $input['appDomain'] = $appDomain;

        $httpPort = $input['httpPort'] ?? '';
        if (!$this->state->isValidPort($httpPort)) {
            $this->respondBadRequest('Please enter a valid HTTP port (1-65535)', $wantsStream);
        }

        $httpsPort = $input['httpsPort'] ?? '';
        if (!$this->state->isValidPort($httpsPort)) {
            $this->respondBadRequest('Please enter a valid HTTPS port (1-65535)', $wantsStream);
        }

        $emailCertificates = trim((string) ($input['emailCertificates'] ?? ''));
        if ($emailCertificates === '' || !$this->state->isValidEmailAddress($emailCertificates)) {
            $this->respondBadRequest('Please enter a valid email address', $wantsStream);
        }
        $input['emailCertificates'] = $emailCertificates;

        $opensslKey = trim((string) ($input['opensslKey'] ?? ''));
        if (!$this->state->isValidSecretKey($opensslKey)) {
            $this->respondBadRequest('Secret API key must be 1-64 characters', $wantsStream);
        }
        $input['opensslKey'] = $opensslKey;

        $assistantOpenAIKey = trim((string) ($input['assistantOpenAIKey'] ?? ''));
        $input['assistantOpenAIKey'] = $assistantOpenAIKey;

        $account = [];
        if (!$this->config->isUpgrade()) {
            $accountEmail = trim((string) ($input['accountEmail'] ?? ''));
            if ($accountEmail === '' || !$this->state->isValidEmailAddress($accountEmail)) {
                $this->respondBadRequest('Please enter a valid email address', $wantsStream, Server::STEP_ACCOUNT_SETUP);
            }

            $accountPassword = (string) ($input['accountPassword'] ?? '');
            if (!$this->state->isValidPassword($accountPassword)) {
                $this->respondBadRequest('Password must be at least 8 characters', $wantsStream, Server::STEP_ACCOUNT_SETUP);
            }

            // Derive name from email
            $accountName = $this->deriveNameFromEmail($accountEmail);

            $input['accountEmail'] = $accountEmail;
            $input['accountPassword'] = $accountPassword;

            $account = [
                'name' => $accountName,
                'email' => $accountEmail,
                'password' => $accountPassword,
            ];
        }

        $lockedDatabase = $this->config->getLockedDatabase();
        if (!$lockedDatabase) {
            $database = strtolower(trim((string) ($input['database'] ?? '')));
            if (!$this->state->isValidDatabaseAdapter($database)) {
                $this->respondBadRequest('Please select a supported database', $wantsStream);
            }
            $input['database'] = $database;
        }

        $installId = $this->state->sanitizeInstallId($input['installId'] ?? '');
        if ($installId === '') {
            $installId = bin2hex(random_bytes(8));
        }

        @unlink(Server::INSTALLER_COMPLETE_FILE);

        try {
            $lockResult = $this->state->reserveGlobalLock($installId);
        } catch (\Throwable $e) {
            if ($wantsStream) {
                $this->sendSseEvent(Server::STATUS_ERROR, ['message' => 'Lock failed: ' . $e->getMessage()]);
            } else {
                http_response_code(self::HTTP_INTERNAL_SERVER_ERROR);
                echo json_encode(['success' => false, 'message' => 'Lock failed: ' . $e->getMessage()]);
            }
            return true;
        }

        if ($lockResult !== 'ok') {
            if ($lockResult === 'locked') {
                http_response_code(self::HTTP_CONFLICT);
                echo json_encode(['success' => false, 'message' => 'Installation already in progress']);
            } else {
                http_response_code(self::HTTP_SERVICE_UNAVAILABLE);
                echo json_encode(['success' => false, 'message' => 'Installer lock unavailable']);
            }
            return true;
        }

        $retryStep = $input['retryStep'] ?? null;
        $allowedRetrySteps = [Server::STEP_DOCKER_COMPOSE, Server::STEP_ENV_VARS, Server::STEP_DOCKER_CONTAINERS];
        if (!is_string($retryStep) || !in_array($retryStep, $allowedRetrySteps, true)) {
            $retryStep = null;
        }

        $existingPath = $this->state->progressFilePath($installId);
        $existing = null;
        if (file_exists($existingPath)) {
            $existing = $this->state->readProgressFile($installId);
            if (!empty($existing['steps']) && $retryStep === null) {
                http_response_code(self::HTTP_CONFLICT);
                echo json_encode(['success' => false, 'message' => 'Installation already started']);
                return true;
            }
        }

        try {
            ignore_user_abort(true);
            $this->state->ensureBootstrapped();
            require_once $this->paths['installPhp'];
            $installer = new \Appwrite\Platform\Tasks\Install();

            if ($wantsStream) {
                $this->sendSseEvent('install-id', ['installId' => $installId]);
            }

            $this->state->updateGlobalLock($installId, Server::STATUS_IN_PROGRESS);

            $payloadInput = [
                '_APP_ENV' => 'production',
                '_APP_OPENSSL_KEY_V1' => $input['opensslKey'] ?? '',
                '_APP_DOMAIN' => $input['appDomain'] ?? 'localhost',
                '_APP_DOMAIN_TARGET' => $input['appDomain'] ?? 'localhost',
                '_APP_EMAIL_CERTIFICATES' => $input['emailCertificates'] ?? '',
                '_APP_DB_ADAPTER' => $lockedDatabase ?? ($input['database'] ?? 'mongodb'),
                '_APP_ASSISTANT_OPENAI_API_KEY' => $input['assistantOpenAIKey'] ?? '',
            ];

            if ($this->hasPayload($existing)) {
                $stored = $existing['payload'];
                $fieldsToCompare = [
                    'httpPort',
                    'httpsPort',
                    'database',
                    'appDomain',
                    'emailCertificates',
                ];
                foreach ($fieldsToCompare as $field) {
                    if (isset($stored[$field]) && isset($input[$field])) {
                        $storedValue = (string) $stored[$field];
                        $inputValue = (string) $input[$field];
                        if (in_array($field, ['httpPort', 'httpsPort'], true)) {
                            $storedValue = trim($storedValue);
                            $inputValue = trim($inputValue);
                        }
                        if ($storedValue !== $inputValue) {
                            if ($installId !== '') {
                                $this->state->updateGlobalLock($installId, Server::STATUS_ERROR);
                            }
                            $this->respondBadRequest('Installation payload mismatch', $wantsStream);
                        }
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
                    $incomingHash = $this->state->hashSensitiveValue((string) ($input[$field] ?? ''));
                    if (isset($stored[$hashField])) {
                        if (!hash_equals((string) $stored[$hashField], $incomingHash)) {
                            if ($installId !== '') {
                                $this->state->updateGlobalLock($installId, Server::STATUS_ERROR);
                            }
                            $this->respondBadRequest('Installation payload mismatch', $wantsStream);
                        }
                    } elseif (isset($stored[$field]) && isset($input[$field]) && (string) $stored[$field] !== (string) $input[$field]) {
                        if ($installId !== '') {
                            $this->state->updateGlobalLock($installId, Server::STATUS_ERROR);
                        }
                        $this->respondBadRequest('Installation payload mismatch', $wantsStream);
                    }
                }

                $payloadInput['_APP_DOMAIN'] = $stored['appDomain'] ?? $payloadInput['_APP_DOMAIN'];
                $payloadInput['_APP_DOMAIN_TARGET'] = $stored['appDomain'] ?? $payloadInput['_APP_DOMAIN_TARGET'];
                $payloadInput['_APP_EMAIL_CERTIFICATES'] = $stored['emailCertificates'] ?? $payloadInput['_APP_EMAIL_CERTIFICATES'];
                $payloadInput['_APP_DB_ADAPTER'] = $lockedDatabase ?? ($stored['database'] ?? $payloadInput['_APP_DB_ADAPTER']);
                $input['httpPort'] = $stored['httpPort'] ?? $input['httpPort'] ?? $this->config->getDefaultHttpPort();
                $input['httpsPort'] = $stored['httpsPort'] ?? $input['httpsPort'] ?? $this->config->getDefaultHttpsPort();
            }

            $vars = $this->config->getVars();
            $shouldGenerateSecrets = !$installer->hasExistingConfig() && !$this->config->isUpgrade();
            $envVars = $installer->prepareEnvironmentVariables($payloadInput, $vars, $shouldGenerateSecrets);

            $this->state->writeProgressFile($installId, [
                'payload' => [
                    'httpPort' => $input['httpPort'] ?? $this->config->getDefaultHttpPort(),
                    'httpsPort' => $input['httpsPort'] ?? $this->config->getDefaultHttpsPort(),
                    'database' => $lockedDatabase ?? ($input['database'] ?? 'mongodb'),
                    'appDomain' => $input['appDomain'] ?? 'localhost',
                    'emailCertificates' => $input['emailCertificates'] ?? '',
                    'opensslKeyHash' => $this->state->hashSensitiveValue($input['opensslKey'] ?? ''),
                    'assistantOpenAIKeyHash' => $this->state->hashSensitiveValue($input['assistantOpenAIKey'] ?? ''),
                ],
                'step' => 'start',
                'status' => Server::STATUS_IN_PROGRESS,
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
                $this->state->writeProgressFile($installId, $payload);
                $this->state->updateGlobalLock($installId, Server::STATUS_IN_PROGRESS);
                if ($wantsStream) {
                    $this->sendSseEvent('progress', $payload);
                }
            };

            $installer->performInstallation(
                $input['httpPort'] ?? $this->config->getDefaultHttpPort(),
                $input['httpsPort'] ?? $this->config->getDefaultHttpsPort(),
                $this->config->getOrganization(),
                $this->config->getImage(),
                $envVars,
                $this->config->getNoStart(),
                $progress,
                $retryStep,
                $this->config->isUpgrade(),
                $account
            );

            if ($wantsStream) {
                $this->sendSseEvent('done', ['installId' => $installId, 'success' => true]);
                usleep(self::SSE_KEEPALIVE_DELAY_MICROSECONDS);
                echo ": keepalive\n\n";
                @ob_flush();
                @flush();
                usleep(self::SSE_KEEPALIVE_DELAY_MICROSECONDS);
            } else {
                echo json_encode([
                    'success' => true,
                    'installId' => $installId,
                    'message' => 'Installation completed successfully',
                ]);
            }
            $this->state->updateGlobalLock($installId, Server::STATUS_COMPLETED);
        } catch (\Throwable $e) {
            $this->handleInstallationError($e, $installId, $wantsStream);
        }

        return true;
    }

    private function handleInstallerView(string $uri): bool
    {
        if ($uri !== '/' && $uri !== '') {
            return false;
        }

        $csrfToken = $this->makeCsrf();
        $this->sendInstallerHtmlHeaders();

        $vars = $this->config->getVars();
        $defaultHttpPort = $this->config->getDefaultHttpPort();
        $defaultHttpsPort = $this->config->getDefaultHttpsPort();
        $isUpgrade = $this->config->isUpgrade();
        $lockedDatabase = $this->config->getLockedDatabase();
        $isMock = $this->config->isMock();
        $isLocalInstall = $this->config->isLocal();

        $defaultEmailCertificates = $vars['_APP_EMAIL_CERTIFICATES']['default'] ?? '';
        if ($isMock && empty($defaultEmailCertificates)) {
            $defaultEmailCertificates = 'walterobrien@example.com';
        }

        include $this->paths['views'] . '/installer.phtml';
        return true;
    }

    private function sendInstallerHtmlHeaders(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Security-Policy: ' . implode('; ', self::INSTALLER_CSP));
    }

    private function makeCsrf(): string
    {
        $existing = $_COOKIE[self::CSRF_COOKIE] ?? '';
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(16));
        setcookie(self::CSRF_COOKIE, $token, [
            'path' => '/',
            'samesite' => 'Strict',
            'httponly' => true,
        ]);
        return $token;
    }

    private function validateCsrf(bool $wantsStream): void
    {
        $cookie = $_COOKIE[self::CSRF_COOKIE] ?? '';
        $header = $_SERVER['HTTP_X_APPWRITE_INSTALLER_CSRF'] ?? '';

        if (!is_string($cookie) || !is_string($header) || $cookie === '' || $header === '' || !hash_equals($cookie, $header)) {
            $this->respondBadRequest('Invalid CSRF token', $wantsStream);
        }
    }

    private function sendSseEvent(string $event, array $payload): void
    {
        echo "event: $event\n";
        echo 'data: ' . json_encode($payload) . "\n\n";
        @ob_flush();
        @flush();
    }

    private function respondBadRequest(string $message, bool $wantsStream, string $step = Server::STEP_CONFIG_FILES): void
    {
        if ($wantsStream) {
            $this->sendSseEvent(Server::STATUS_ERROR, ['message' => $message, 'step' => $step]);
        } else {
            http_response_code(self::HTTP_BAD_REQUEST);
            echo json_encode(['success' => false, 'message' => $message]);
        }
        exit;
    }

    private function buildErrorDetails(\Throwable $e): array
    {
        $details = ['trace' => $e->getTraceAsString()];
        $previous = $e->getPrevious();
        if ($previous instanceof \Throwable && $previous->getMessage() !== '') {
            $details['output'] = $previous->getMessage();
        }
        return $details;
    }

    private function hasPayload(mixed $data): bool
    {
        return is_array($data) && isset($data['payload']) && is_array($data['payload']);
    }

    private function handleInstallationError(\Throwable $e, string $installId, bool $wantsStream): void
    {
        http_response_code(self::HTTP_INTERNAL_SERVER_ERROR);

        if ($installId !== '') {
            $this->state->writeProgressFile($installId, [
                'step' => Server::STATUS_ERROR,
                'status' => Server::STATUS_ERROR,
                'message' => $e->getMessage(),
                'details' => $this->buildErrorDetails($e),
                'updatedAt' => time(),
            ]);
            $this->state->updateGlobalLock($installId, Server::STATUS_ERROR);
        }

        @unlink(Server::INSTALLER_CONFIG_FILE);

        if ($wantsStream) {
            $this->sendSseEvent(Server::STATUS_ERROR, [
                'message' => $e->getMessage(),
                'details' => $this->buildErrorDetails($e)
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Derives a name from an email address
     * Examples:
     *   admin@example.com -> Admin
     *   admin.123@example.com -> Admin123
     */
    private function deriveNameFromEmail(string $email): string
    {
        // extract before @ symbol
        $parts = explode('@', $email);
        $username = $parts[0] ?? '';

        // remove all non-alphanumeric characters
        $cleaned = preg_replace('/[^a-zA-Z0-9]/', '', $username);

        // capitalize
        return ucfirst($cleaned);
    }
}
