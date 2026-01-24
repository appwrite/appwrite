<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Docker\Compose;
use Appwrite\Docker\Env;
use Appwrite\Platform\Installer\Server as InstallerServer;
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
    private const int INSTALL_STEP_DELAY_SECONDS = 2;
    private const int WEB_SERVER_CHECK_ATTEMPTS = 10;
    private const int WEB_SERVER_CHECK_DELAY_SECONDS = 1;

    protected string $hostPath = '';
    protected ?bool $isLocalInstall = null;
    protected ?array $installerConfig = null;
    protected string $path = '/usr/src/code/appwrite';

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

        $isLocalInstall = $this->isLocalInstall();
        $this->applyLocalPaths($isLocalInstall, true);

        // Create directory with write permissions
        if (!\file_exists(\dirname($this->path))) {
            if (!@\mkdir(\dirname($this->path), 0755, true)) {
                Console::error('Can\'t create directory ' . \dirname($this->path));
                Console::exit(1);
            }
        }

        // Check for existing installation
        $data = $this->readExistingCompose();
        $existingInstallation = $data !== '';

        if ($existingInstallation) {
            $time = \time();
            $composeFileName = $this->getComposeFileName();
            Console::info('Compose file found, creating backup: ' . $composeFileName . '.' . $time . '.backup');
            file_put_contents($this->path . '/' . $composeFileName . '.' . $time . '.backup', $data);
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

                $envData = @file_get_contents($this->path . '/' . $this->getEnvFileName());

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
            Console::info('Open your browser at: http://localhost:' . InstallerServer::INSTALLER_WEB_PORT);
            Console::info('Press Ctrl+C to cancel installation');

            $this->startWebServer($defaultHTTPPort, $defaultHTTPSPort, $organization, $image, $noStart, $vars);
            return;
        }

        // Fall back to CLI mode
        $enableAssistant = false;
        $assistantExistsInOldCompose = false;
        if ($existingInstallation && isset($compose)) {
            try {
                $assistantService = $compose->getService('appwrite-assistant');
                $assistantExistsInOldCompose = $assistantService !== null;
            } catch (\Throwable) {
                /* ignore */
            }
        }

        if ($interactive === 'Y' && Console::isInteractive()) {
            $prompt = 'Add Appwrite Assistant? (Y/n)' . ($assistantExistsInOldCompose ? ' [Currently enabled]' : '');
            $answer = Console::confirm($prompt);

            if (empty($answer)) {
                $enableAssistant = $assistantExistsInOldCompose;
            } else {
                $enableAssistant = \strtolower($answer) === 'y';
            }
        } elseif ($assistantExistsInOldCompose) {
            $enableAssistant = true;
        }

        if (empty($httpPort)) {
            $httpPort = Console::confirm('Choose your server HTTP port: (default: ' . $defaultHTTPPort . ')');
            $httpPort = ($httpPort) ? $httpPort : $defaultHTTPPort;
        }

        if (empty($httpsPort)) {
            $httpsPort = Console::confirm('Choose your server HTTPS port: (default: ' . $defaultHTTPSPort . ')');
            $httpsPort = ($httpsPort) ? $httpsPort : $defaultHTTPSPort;
        }

        $userInput = [];
        foreach ($vars as $var) {
            if ($var['name'] === '_APP_ASSISTANT_OPENAI_API_KEY') {
                if (!$enableAssistant) {
                    $userInput[$var['name']] = '';
                    continue;
                }

                if (!empty($var['default'])) {
                    $userInput[$var['name']] = $var['default'];
                    continue;
                }

                if (Console::isInteractive() && $interactive === 'Y') {
                    $userInput[$var['name']] = Console::confirm('Enter your OpenAI API key for Appwrite Assistant:');
                    if (empty($userInput[$var['name']])) {
                        Console::warning('No API key provided. Assistant will be disabled.');
                        $enableAssistant = false;
                        $userInput[$var['name']] = '';
                    }
                } else {
                    $userInput[$var['name']] = '';
                }

                continue;
            }

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


    protected function startWebServer(string $defaultHTTPPort, string $defaultHTTPSPort, string $organization, string $image, bool $noStart, array $vars, bool $isUpgrade = false, ?string $lockedDatabase = null): void
    {
        $host = '0.0.0.0';
        $port = $this->isLocalInstall()
            ? InstallerServer::INSTALLER_WEB_PORT_INTERNAL
            : InstallerServer::INSTALLER_WEB_PORT;

        $this->setInstallerConfig([
            'defaultHttpPort' => $defaultHTTPPort,
            'defaultHttpsPort' => $defaultHTTPSPort,
            'organization' => $organization,
            'image' => $image,
            'noStart' => $noStart,
            'vars' => $vars,
            'isUpgrade' => $isUpgrade,
            'lockedDatabase' => $lockedDatabase,
            'isLocal' => $this->isLocalInstall(),
            'hostPath' => $this->hostPath ?: null,
        ]);
        $routerScript = dirname(__DIR__) . '/Installer/Server.php';
        $docroot = $this->buildFromProjectPath('/app/views/install');

        // Start PHP built-in server in background
        $command = \sprintf(
            'php -S %s:%d -t %s %s 2>&1 & echo $!',
            $host,
            $port,
            \escapeshellarg($docroot),
            \escapeshellarg($routerScript)
        );
        $output = [];
        \exec($command, $output);
        $pid = isset($output[0]) ? (int) $output[0] : 0;

        \register_shutdown_function(function () use ($pid) {
            if ($pid > 0 && \function_exists('posix_kill')) {
                @\posix_kill($pid, SIGTERM);
            }
        });
        \sleep(3);

        if (!$this->waitForWebServer($port)) {
            Console::warning('Web installer did not respond in time. Please refresh the browser.');
            return;
        }

        // Wait for the server process to finish
        while (true) {
            $handle = @fsockopen('localhost', $port, $errno, $errstr, 1);
            if ($handle === false) {
                break;
            }
            \fclose($handle);
            \sleep(1);
        }
    }

    public function prepareEnvironmentVariables(array $userInput, array $vars): array
    {
        $input = [];
        $password = new Password();
        $token = new Token();

        // Start with all defaults
        foreach ($vars as $var) {
            $default = $var['default'] ?? null;
            $hasDefault = $default !== null && $default !== '';
            if (!empty($var['filter']) && $var['filter'] === 'token') {
                $input[$var['name']] = $hasDefault ? $default : $token->generate();
            } elseif (!empty($var['filter']) && $var['filter'] === 'password') {
                /*;#+@:/?& broke DSNs locally */
                $input[$var['name']] = $hasDefault
                    ? $default
                    : $this->generatePasswordValue($var['name'], $password);
            } else {
                $input[$var['name']] = $default;
            }
        }

        // Override with user inputs
        foreach ($userInput as $key => $value) {
            if ($value !== null && ($value !== '' || $key === '_APP_ASSISTANT_OPENAI_API_KEY')) {
                $input[$key] = $value;
            }
        }

        foreach ($input as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new \InvalidArgumentException('Invalid value for ' . $key);
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

    private function updateProgress(?callable $progress, string $step, string $status, array $messages = [], array $details = [], ?string $messageOverride = null): void
    {
        if (!$progress) {
            return;
        }

        if ($messageOverride !== null) {
            $message = $messageOverride;
        } else {
            $key = $status === InstallerServer::STATUS_COMPLETED ? 'done' : 'start';
            $message = $messages[$step][$key] ?? null;
            if ($message === null) {
                return;
            }
        }

        try {
            $progress($step, $status, $message, $details);
        } catch (\Throwable $e) {
        }
        if ($status === InstallerServer::STATUS_IN_PROGRESS) {
            sleep(self::INSTALL_STEP_DELAY_SECONDS);
        }
    }

    private function setInstallerConfig(array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        putenv('APPWRITE_INSTALLER_CONFIG=' . $json);
        $path = InstallerServer::INSTALLER_CONFIG_FILE;
        if (@file_put_contents($path, $json) === false) {
            return;
        }
        @chmod($path, 0600);
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
        $isLocalInstall = $this->isLocalInstall();
        $this->applyLocalPaths($isLocalInstall, false);

        $isCLI = php_sapi_name() === 'cli';
        if ($isLocalInstall) {
            $useExistingConfig = false;
        } else {
            $useExistingConfig = file_exists($this->path . '/' . $this->getComposeFileName())
                    && file_exists($this->path . '/' . $this->getEnvFileName());
        }

        if ($isLocalInstall) {
            $organization = 'appwrite';
            $image = 'appwrite';
        }

        $templateForEnv = new View($this->buildFromProjectPath('/app/views/install/env.phtml'));
        $templateForCompose = new View($this->buildFromProjectPath('/app/views/install/compose.phtml'));

        $database = $input['_APP_DB_ADAPTER'] ?? 'mongodb';

        $version = \defined('APP_VERSION_STABLE') ? APP_VERSION_STABLE : 'latest';
        if ($isLocalInstall) {
            $version = 'local';
        }

        $assistantKey = (string) ($input['_APP_ASSISTANT_OPENAI_API_KEY'] ?? '');
        $enableAssistant = trim($assistantKey) !== '';

        $templateForCompose
            ->setParam('httpPort', $httpPort)
            ->setParam('httpsPort', $httpsPort)
            ->setParam('version', $version)
            ->setParam('organization', $organization)
            ->setParam('image', $image)
            ->setParam('database', $database)
            ->setParam('hostPath', $this->hostPath)
            ->setParam('enableAssistant', $enableAssistant);

        $templateForEnv->setParam('vars', $input);

        $steps = [
            InstallerServer::STEP_DOCKER_COMPOSE,
            InstallerServer::STEP_ENV_VARS,
            InstallerServer::STEP_DOCKER_CONTAINERS
        ];

        $startIndex = 0;
        if ($resumeFromStep !== null) {
            $resumeIndex = array_search($resumeFromStep, $steps, true);
            if ($resumeIndex !== false) {
                $startIndex = $resumeIndex;
            }
        }

        $currentStep = null;

        $messages = $this->buildStepMessages($isUpgrade);

        try {
            if ($startIndex <= 1) {
                $this->updateProgress($progress, InstallerServer::STEP_CONFIG_FILES, InstallerServer::STATUS_IN_PROGRESS, $messages);
            }

            if ($startIndex <= 0) {
                $currentStep = InstallerServer::STEP_DOCKER_COMPOSE;
                $this->updateProgress($progress, InstallerServer::STEP_DOCKER_COMPOSE, InstallerServer::STATUS_IN_PROGRESS, $messages);

                if (!$useExistingConfig) {
                    $this->writeComposeFile($templateForCompose, $isLocalInstall);
                }

                $this->updateProgress($progress, InstallerServer::STEP_DOCKER_COMPOSE, InstallerServer::STATUS_COMPLETED, $messages);
            }

            if ($startIndex <= 1) {
                $currentStep = InstallerServer::STEP_ENV_VARS;
                $this->updateProgress($progress, InstallerServer::STEP_ENV_VARS, InstallerServer::STATUS_IN_PROGRESS, $messages);

                if (!$useExistingConfig) {
                    $this->writeEnvFile($templateForEnv, $isLocalInstall);
                }

                $this->updateProgress($progress, InstallerServer::STEP_ENV_VARS, InstallerServer::STATUS_COMPLETED, $messages);
                $this->updateProgress($progress, InstallerServer::STEP_CONFIG_FILES, InstallerServer::STATUS_COMPLETED, $messages);
            }

            if ($database === 'mongodb' && !$useExistingConfig) {
                $this->copyMongoEntrypointIfNeeded();
            }

            if (!$noStart && $startIndex <= 2) {
                $currentStep = InstallerServer::STEP_DOCKER_CONTAINERS;
                $this->updateProgress($progress, InstallerServer::STEP_DOCKER_CONTAINERS, InstallerServer::STATUS_IN_PROGRESS, $messages);
                $this->runDockerCompose($input, $isLocalInstall, $useExistingConfig, $isCLI);

                $this->updateProgress($progress, InstallerServer::STEP_DOCKER_CONTAINERS, InstallerServer::STATUS_COMPLETED, $messages);

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
                $this->updateProgress($progress, $currentStep, InstallerServer::STATUS_ERROR, $messages, $details, $e->getMessage());
            }
            throw $e;
        }
    }

    private function buildStepMessages(bool $isUpgrade): array
    {
        $isUpgradeLabel = $isUpgrade ? 'updated' : 'created';
        $verbs = [
            InstallerServer::STEP_CONFIG_FILES => $isUpgrade ? 'Updating' : 'Creating',
            InstallerServer::STEP_DOCKER_COMPOSE => $isUpgrade ? 'Updating' : 'Generating',
            InstallerServer::STEP_ENV_VARS => $isUpgrade ? 'Updating' : 'Configuring',
            InstallerServer::STEP_DOCKER_CONTAINERS => $isUpgrade ? 'Restarting' : 'Starting',
        ];

        return [
            InstallerServer::STEP_CONFIG_FILES => [
                'start' => $verbs[InstallerServer::STEP_CONFIG_FILES] . ' configuration files...',
                'done' => 'Configuration files ' . $isUpgradeLabel,
            ],
            InstallerServer::STEP_DOCKER_COMPOSE => [
                'start' => $verbs[InstallerServer::STEP_DOCKER_COMPOSE] . ' Docker Compose file...',
                'done' => 'Docker Compose file ' . $isUpgradeLabel,
            ],
            InstallerServer::STEP_ENV_VARS => [
                'start' => $verbs[InstallerServer::STEP_ENV_VARS] . ' environment variables...',
                'done' => 'Environment variables ' . $isUpgradeLabel,
            ],
            InstallerServer::STEP_DOCKER_CONTAINERS => [
                'start' => $verbs[InstallerServer::STEP_DOCKER_CONTAINERS] . ' Docker containers...',
                'done' => $isUpgrade ? 'Docker containers restarted' : 'Docker containers started',
            ],
        ];
    }

    private function writeComposeFile(View $template, bool $isLocalInstall): void
    {
        // Always use container path for file operations
        // Use a separate file for web installer to avoid conflicts
        $composeFileName = $this->getComposeFileName();
        $targetPath = $this->path . '/' . $composeFileName;
        $renderedContent = $template->render(false);

        $result = @file_put_contents($targetPath, $renderedContent);
        if ($result === false) {
            $lastError = error_get_last();
            $errorMsg = $lastError ? $lastError['message'] : 'Unknown error';
            throw new \Exception('Failed to save Docker Compose file: ' . $errorMsg . ' (path: ' . $targetPath . ')');
        }
    }

    private function writeEnvFile(View $template, bool $isLocalInstall): void
    {
        // Always use container path for file operations
        // Use a separate env file for installer to avoid conflicts
        $envFileName = $this->getEnvFileName();
        if (!\file_put_contents($this->path . '/' . $envFileName, $template->render(false))) {
            throw new \Exception('Failed to save environment variables file');
        }
    }

    private function copyMongoEntrypointIfNeeded(): void
    {
        $mongoEntrypoint = $this->buildFromProjectPath('/mongo-entrypoint.sh');

        if (file_exists($mongoEntrypoint)) {
            // Always use container path for file operations
            copy($mongoEntrypoint, $this->path . '/mongo-entrypoint.sh');
        }
    }

    protected function runDockerCompose(array $input, bool $isLocalInstall, bool $useExistingConfig, bool $isCLI): void
    {
        $env = '';
        if (!$useExistingConfig) {
            foreach ($input as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                if (!preg_match('/^[A-Z0-9_]+$/', $key)) {
                    throw new \Exception('Invalid environment variable name');
                }
                $env .= $key . '=' . \escapeshellarg((string) $value) . ' ';
            }
        }

        if ($isCLI) {
            Console::log("Running \"docker compose up -d --remove-orphans --renew-anon-volumes\"");
        }

        // Docker compose runs inside container, so use container paths
        // The compose file itself contains host paths for volume mounts
        $composeFileName = $this->getComposeFileName();
        $composeFile = $this->path . '/' . $composeFileName;

        $command = [
            'docker',
            'compose',
            '-f',
            $composeFile,
        ];

        if ($isLocalInstall) {
            $command[] = '--project-name';
            $command[] = 'appwrite';
        }

        $command[] = '--project-directory';
        $command[] = $this->path;
        $command[] = 'up';
        $command[] = '-d';
        $command[] = '--remove-orphans';
        $command[] = '--renew-anon-volumes';
        $escaped = array_map('escapeshellarg', $command);
        $commandLine = $env . implode(' ', $escaped) . ' 2>&1';
        \exec($commandLine, $output, $exit);

        if ($exit !== 0) {
            $message = trim(implode("\n", $output));
            $previous = $message !== '' ? new \RuntimeException($message) : null;
            throw new \RuntimeException('Failed to start containers', 0, $previous);
        }
        if ($isLocalInstall && $isCLI && !empty($output)) {
            Console::log(implode("\n", $output));
        }
    }

    protected function isLocalInstall(): bool
    {
        $config = null;
        if ($this->isLocalInstall === null) {
            $config = $this->readInstallerConfig();
            $this->isLocalInstall = !empty($config['isLocal']);
        }

        return $this->isLocalInstall;
    }

    protected function readInstallerConfig(): array
    {
        if ($this->installerConfig !== null) {
            return $this->installerConfig;
        }

        $this->installerConfig = [];
        $decodeConfig = static function (string $json): ?array {
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : null;
        };

        $json = getenv('APPWRITE_INSTALLER_CONFIG');
        $path = InstallerServer::INSTALLER_CONFIG_FILE;
        $fileJson = file_exists($path) ? file_get_contents($path) : null;

        foreach ([$json, $fileJson] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $decoded = $decodeConfig($candidate);
            if ($decoded !== null) {
                $this->installerConfig = $decoded;
                return $this->installerConfig;
            }
        }

        return $this->installerConfig;
    }

    protected function getInstallerHostPath(): string
    {
        $config = $this->readInstallerConfig();
        if (!empty($config['hostPath'])) {
            return (string) $config['hostPath'];
        }

        $cwd = getcwd();
        return $cwd !== false ? $cwd : '.';
    }

    protected function buildFromProjectPath(string $suffix): string
    {
        if ($suffix !== '' && $suffix[0] !== '/') {
            $suffix = '/' . $suffix;
        }
        return dirname(__DIR__, 4) . $suffix;
    }

    protected function applyLocalPaths(bool $isLocalInstall, bool $force = false): void
    {
        if (!$isLocalInstall) {
            return;
        }
        if (!$force && $this->hostPath !== '') {
            return;
        }
        $this->path = '/usr/src/code';
        $this->hostPath = $this->getInstallerHostPath();
    }

    protected function readExistingCompose(): string
    {
        $composeFile = $this->path . '/' . $this->getComposeFileName();
        $data = @file_get_contents($composeFile);
        return !empty($data) ? $data : '';
    }

    protected function generatePasswordValue(string $varName, Password $password): string
    {
        $value = $password->generate();
        if (!\preg_match('/^_APP_DB_.*_PASS$/', $varName)) {
            return $value;
        }

        return rtrim(strtr(base64_encode(hash('sha256', $value, true)), '+/', '-_'), '=');
    }

    protected function getComposeFileName(): string
    {
        return $this->isLocalInstall() ? 'docker-compose.web-installer.yml' : 'docker-compose.yml';
    }

    protected function getEnvFileName(): string
    {
        return $this->isLocalInstall() ? '.env.web-installer' : '.env';
    }

    private function waitForWebServer(int $port): bool
    {
        for ($attempt = 0; $attempt < self::WEB_SERVER_CHECK_ATTEMPTS; $attempt++) {
            $handle = @fsockopen('localhost', $port, $errno, $errstr, 1);
            if ($handle !== false) {
                \fclose($handle);
                return true;
            }
            \sleep(self::WEB_SERVER_CHECK_DELAY_SECONDS);
        }
        return false;
    }
}
