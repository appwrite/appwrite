<?php

namespace Appwrite\Platform\Installer\Runtime;

use Appwrite\Platform\Installer\Server;

class State
{
    private const string PATTERN_DIGITS_ONLY = '/^\d+$/';
    private const string PATTERN_HAS_NON_WHITESPACE = '/\S/';
    private const string PATTERN_LINE_BREAKS = '/\r\n|\n|\r/';
    private const string PATTERN_INSTALL_ID_SANITIZE = '/[^a-zA-Z0-9_-]/';
    private const string PATTERN_IPV6_WITH_PORT = '/^\[(.+)](?::(\d+))?$/';

    private const int CONFIG_FILE_PERMISSION = 0600;
    private const int GLOBAL_LOCK_TIMEOUT_SECONDS = 3600;

    private const int PORT_MIN = 1;
    private const int PORT_MAX = 65535;

    private array $paths;
    private bool $bootstrapped = false;

    public function __construct(array $paths)
    {
        $this->paths = $paths;
    }

    public function buildConfig(array $overrides = [], bool $useEnv = true): Config
    {
        $cfg = new Config();
        $configJson = null;
        $decodedOk = false;
        if ($useEnv) {
            $configJson = getenv('APPWRITE_INSTALLER_CONFIG');
            if ($configJson !== false && $configJson !== '') {
                $decoded = json_decode($configJson, true);
                if (is_array($decoded)) {
                    $cfg->apply($decoded);
                    $decodedOk = true;
                }
            }
        }
        if ($useEnv && (!$decodedOk)) {
            $fileConfig = $this->readConfigFile();
            if (is_array($fileConfig)) {
                $cfg->apply($fileConfig);
            }
        }

        if ($cfg->isLocal() && empty($cfg->getVars())) {
            $envPath = dirname($this->paths['init'], 2) . '/.env';
            if (file_exists($envPath)) {
                $envContent = file_get_contents($envPath);
                if ($envContent !== false) {
                    $vars = $this->parseEnvFile($envContent);
                    if (!empty($vars)) {
                        $cfg->setVars($vars);
                    }
                }
            }
        }

        $cfg->apply($overrides);

        return $cfg;
    }

    public function applyEnvConfig(Config|array $cfg): void
    {
        $values = $cfg instanceof Config ? $cfg->toArray() : $cfg;
        $json = json_encode($values, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        putenv('APPWRITE_INSTALLER_CONFIG=' . $json);
        $this->writeConfigFile($json);
    }

    private function readConfigFile(): ?array
    {
        $path = Server::INSTALLER_CONFIG_FILE;
        if (!file_exists($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }
        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeConfigFile(string $json): void
    {
        $path = Server::INSTALLER_CONFIG_FILE;
        if (@file_put_contents($path, $json) === false) {
            return;
        }
        @chmod($path, self::CONFIG_FILE_PERMISSION);
    }


    public function ensureBootstrapped(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        require_once $this->paths['vendor'];
        require_once $this->paths['init'];
        $this->bootstrapped = true;
    }

    public function sanitizeInstallId($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $clean = preg_replace(self::PATTERN_INSTALL_ID_SANITIZE, '', $value);
        if (!is_string($clean)) {
            return '';
        }

        return substr($clean, 0, 64);
    }

    public function hashSensitiveValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        return hash('sha256', $trimmed);
    }

    public function isValidPort($value): bool
    {
        $string = (string) $value;
        if ($string === '' || !preg_match(self::PATTERN_DIGITS_ONLY, $string)) {
            return false;
        }
        $port = (int) $string;
        return $port >= self::PORT_MIN && $port <= self::PORT_MAX;
    }

    public function isValidEmailAddress(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function isValidPassword(string $value): bool
    {
        return strlen($value) >= 8 && preg_match(self::PATTERN_HAS_NON_WHITESPACE, $value) === 1;
    }

    public function isValidSecretKey(string $value): bool
    {
        return $value !== '' && strlen($value) <= 64;
    }

    public function isValidAccountName(string $value): bool
    {
        return trim($value) !== '';
    }

    public function isValidAppDomainInput(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $host = $value;
        $port = null;

        if (str_starts_with($value, '[')) {
            if (!preg_match(self::PATTERN_IPV6_WITH_PORT, $value, $matches)) {
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

        if ($port !== null && $port !== '' && !$this->isValidPort($port)) {
            return false;
        }

        return $this->isValidAppDomain($host);
    }

    public function isValidDatabaseAdapter(string $value): bool
    {
        return in_array($value, ['mongodb', 'mariadb'], true);
    }

    public function progressFilePath(string $installId): string
    {
        return sys_get_temp_dir() . '/appwrite-install-' . $installId . '.json';
    }

    public function reserveGlobalLock(string $installId): string
    {
        return (string) $this->withGlobalLock(function ($handle, $lock) use ($installId) {
            if (!$handle) {
                return 'unavailable';
            }
            if ($this->isGlobalLockActive($lock) && ($lock['installId'] ?? '') !== $installId) {
                return 'locked';
            }
            $payload = [
                'installId' => $installId,
                'status' => Server::STATUS_IN_PROGRESS,
                'updatedAt' => time(),
            ];
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($payload));
            return 'ok';
        });
    }

    public function updateGlobalLock(string $installId, string $status): void
    {
        $this->withGlobalLock(function ($handle, $lock) use ($installId, $status) {
            if (!$handle) {
                return;
            }
            if ($this->isGlobalLockActive($lock) && ($lock['installId'] ?? '') !== $installId) {
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

    public function readProgressFile(string $installId): array
    {
        $path = $this->progressFilePath($installId);
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

    public function writeProgressFile(string $installId, array $payload): void
    {
        $data = $this->readProgressFile($installId);
        if (!isset($data['steps']) || !is_array($data['steps'])) {
            $data['steps'] = [];
        }

        if (!empty($payload['step'])) {
            $data['steps'][$payload['step']] = [
                'status' => $payload['status'] ?? Server::STATUS_IN_PROGRESS,
                'message' => $payload['message'] ?? '',
                'updatedAt' => $payload['updatedAt'] ?? time(),
            ];
        }

        if (!empty($payload['status']) && $payload['status'] === Server::STATUS_ERROR) {
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

        file_put_contents($this->progressFilePath($installId), json_encode($data));
    }

    private function parseEnvFile(string $contents): array
    {
        $vars = [];
        foreach ((array) preg_split(self::PATTERN_LINE_BREAKS, $contents) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key === '') {
                continue;
            }
            $value = $this->stripEnvQuotes($value);

            $vars[] = [
                'name' => $key,
                'default' => $value,
            ];
        }

        return $vars;
    }

    private function stripEnvQuotes(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        $first = $value[0];
        $last = substr($value, -1);
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }
        return $value;
    }

    private function globalLockPath(): string
    {
        return Server::INSTALLER_LOCK_FILE;
    }

    private function isGlobalLockActive(?array $lock): bool
    {
        if (!$lock || !isset($lock['updatedAt'])) {
            return false;
        }

        if (isset($lock['status']) && in_array($lock['status'], [Server::STATUS_COMPLETED, Server::STATUS_ERROR], true)) {
            return false;
        }

        if (time() - (int) $lock['updatedAt'] > self::GLOBAL_LOCK_TIMEOUT_SECONDS) {
            return false;
        }

        return true;
    }

    private function withGlobalLock(callable $callback)
    {
        $path = $this->globalLockPath();
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

        try {
            $result = $callback($handle, $lock);
        } finally {
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $result;
    }

    private function isValidAppDomain(string $value): bool
    {
        if ($value === 'localhost') {
            return true;
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        return filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
