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
use Utopia\Validator\WhiteList;

class Install extends Action
{
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

    public function action(string $httpPort, string $httpsPort, string $organization, string $image, string $interactive, bool $noStart): void
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
            Console::info('Compose file found, creating backup: docker-compose.yml.' . $time . '.backup');
            file_put_contents($this->path . '/docker-compose.yml.' . $time . '.backup', $data);
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
                    Console::info('Env file found, creating backup: .env.' . $time . '.backup');
                    file_put_contents($this->path . '/.env.' . $time . '.backup', $envData);
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
        $this->performInstallation($httpPort, $httpsPort, $organization, $image, $input, $noStart);
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
            'php -S %s:%d %s >/dev/null 2>&1 &',
            $host,
            $port,
            \escapeshellarg($routerScript)
        );

        // Start the server
        \exec($command, $output, $exitCode);
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
                // Server has stopped
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

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve static files
if ($uri !== '/' && $uri !== '') {
    $filePath = PUBLIC_PATH . $uri;
    if (file_exists($filePath) && is_file($filePath)) {
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

// Handle POST request (installation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $uri === '/install') {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    try {
        require_once INSTALL_PHP_PATH;
        $installer = new \Appwrite\Platform\Tasks\Install();

        // Prepare user inputs - use locked database if in upgrade mode
        $userInput = [
            '_APP_ENV' => 'production',
            '_APP_OPENSSL_KEY_V1' => $input['opensslKey'] ?? '',
            '_APP_DOMAIN' => $input['appDomain'] ?? 'localhost',
            '_APP_DOMAIN_TARGET' => $input['appDomain'] ?? 'localhost',
            '_APP_EMAIL_CERTIFICATES' => $input['emailCertificates'] ?? '',
            '_APP_DB_ADAPTER' => LOCKED_DATABASE ?? ($input['database'] ?? 'mongodb'),
        ];

        // Use the prepareEnvironmentVariables method to merge with defaults
        $vars = json_decode(VARS_JSON, true);
        $envVars = $installer->prepareEnvironmentVariables($userInput, $vars);

        // Call performInstallation method
        $installer->performInstallation(
            $input['httpPort'] ?? DEFAULT_HTTP_PORT,
            $input['httpsPort'] ?? DEFAULT_HTTPS_PORT,
            ORGANIZATION,
            IMAGE,
            $envVars,
            NO_START
        );

        echo json_encode(['success' => true, 'message' => 'Installation completed successfully']);

        // Stop the server after successful installation
        register_shutdown_function(function() {
            sleep(2); // Give time for response to be sent
            posix_kill(posix_getpid(), SIGTERM);
        });

    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Serve installer UI
if ($uri === '/' || $uri === '') {
    header('Content-Type: text/html');

    $vars = json_decode(VARS_JSON, true);
    $defaultHttpPort = DEFAULT_HTTP_PORT;
    $defaultHttpsPort = DEFAULT_HTTPS_PORT;
    $isUpgrade = IS_UPGRADE;
    $lockedDatabase = LOCKED_DATABASE;

    include APP_VIEWS_PATH . '/installer.phtml';
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

    public function performInstallation(
        string $httpPort,
        string $httpsPort,
        string $organization,
        string $image,
        array $input,
        bool $noStart
    ): void {
        // Check if we're running in CLI context
        $isCLI = php_sapi_name() === 'cli';

        $templateForCompose = new View(__DIR__ . '/../../../../app/views/install/compose.phtml');
        $templateForEnv = new View(__DIR__ . '/../../../../app/views/install/env.phtml');

        $database = $input['_APP_DB_ADAPTER'] ?? 'mongodb';

        $templateForCompose
            ->setParam('httpPort', $httpPort)
            ->setParam('httpsPort', $httpsPort)
            ->setParam('version', 'next')
            ->setParam('organization', $organization)
            ->setParam('image', $image)
            ->setParam('database', $database)
            ->setParam('hostPath', $this->hostPath);

        $templateForEnv->setParam('vars', $input);

        if (!\file_put_contents($this->path . '/docker-compose.yml', $templateForCompose->render(false))) {
            throw new \Exception('Failed to save Docker Compose file');
        }

        if (!\file_put_contents($this->path . '/.env', $templateForEnv->render(false))) {
            throw new \Exception('Failed to save environment variables file');
        }

        // Copy MongoDB entrypoint script for replica set setup
        if ($database === 'mongodb') {
            $mongoEntrypoint = __DIR__ . '/../../../../mongo-entrypoint.sh';

            if (file_exists($mongoEntrypoint)) {
                copy($mongoEntrypoint, $this->path . '/mongo-entrypoint.sh');
            }
        }

        if (!$noStart) {
            $env = '';
            foreach ($input as $key => $value) {
                if ($value) {
                    $env .= $key . '=' . \escapeshellarg($value) . ' ';
                }
            }

            if ($isCLI) {
                Console::log("Running \"docker compose up -d --remove-orphans --renew-anon-volumes\"");
            }

            $command = "$env docker compose --project-directory $this->path up -d --remove-orphans --renew-anon-volumes 2>&1";
            \exec($command, $output, $exit);

            if ($exit !== 0) {
                throw new \Exception('Failed to start Appwrite: ' . implode("\n", $output));
            }

            if ($isCLI) {
                Console::success('Appwrite installed successfully');
            }
        } else {
            if ($isCLI) {
                Console::success('Installation files created. Run "docker compose up -d" to start Appwrite');
            }
        }
    }
}
