<?php

declare(strict_types=1);

namespace Tests\E2E\Installer;

use Appwrite\Platform\Installer\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstallerSessionTest extends TestCase
{
    private const string TEST_SECRET = 'installer-e2e-secret';

    private mixed $server = null;
    private string $endpoint;
    private string $readyFile;
    private string|false $previousSecret = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSecret = getenv('APPWRITE_INSTALLER_SECRET');
        putenv('APPWRITE_INSTALLER_SECRET=' . self::TEST_SECRET);

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException('Unable to reserve a port for the installer E2E server: ' . $errorMessage);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($address === false) {
            throw new RuntimeException('Unable to read the installer E2E server port');
        }

        $port = (int) substr($address, strrpos($address, ':') + 1);
        $this->endpoint = 'http://127.0.0.1:' . $port;
        $readyFile = tempnam(sys_get_temp_dir(), 'appwrite-installer-e2e-');
        if ($readyFile === false) {
            throw new RuntimeException('Unable to create the installer E2E ready file');
        }
        $this->readyFile = $readyFile;

        $root = dirname(__DIR__, 3);
        $this->server = proc_open([
            PHP_BINARY,
            $root . '/src/Appwrite/Platform/Installer/Server.php',
            '--port=' . $port,
            '--ready-file=' . $this->readyFile,
        ], [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ], $pipes, $root);

        if (!is_resource($this->server)) {
            throw new RuntimeException('Unable to start the installer E2E server');
        }
        fclose($pipes[0]);

        $deadline = microtime(true) + 10;
        while (!is_file($this->readyFile) && microtime(true) < $deadline) {
            if (!proc_get_status($this->server)['running']) {
                break;
            }
            usleep(50000);
        }
        $this->assertFileExists($this->readyFile, 'Installer E2E server did not become ready');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            if (proc_get_status($this->server)['running']) {
                proc_terminate($this->server);
            }
            proc_close($this->server);
        }

        if (isset($this->readyFile) && is_file($this->readyFile)) {
            unlink($this->readyFile);
        }

        if ($this->previousSecret === false) {
            putenv('APPWRITE_INSTALLER_SECRET');
        } else {
            putenv('APPWRITE_INSTALLER_SECRET=' . $this->previousSecret);
        }

        parent::tearDown();
    }

    public function testUrlCapabilityExchangesForHttpOnlySessionCookie(): void
    {
        [$status, $headers] = $this->request('GET', '/?secret=' . rawurlencode(self::TEST_SECRET));

        $this->assertSame(303, $status);
        $this->assertSame(['/'], $headers['location'] ?? []);
        $this->assertSame(['no-referrer'], $headers['referrer-policy'] ?? []);
        $this->assertSame(['no-store'], $headers['cache-control'] ?? []);
        $sessionSetCookie = $this->setCookie($headers, Server::SESSION_COOKIE);
        $sessionCookie = $this->cookieValue($sessionSetCookie);
        $this->assertNotSame(self::TEST_SECRET, $sessionCookie);
        $this->assertStringContainsString('; path=/install', strtolower($sessionSetCookie));
        $this->assertStringContainsString('; httponly', strtolower($sessionSetCookie));
        $this->assertStringContainsString('; samesite=strict', strtolower($sessionSetCookie));
        $expires = $this->cookieExpiry($sessionSetCookie);
        $this->assertGreaterThan(time(), $expires);
        $this->assertLessThanOrEqual(time() + Server::SESSION_TTL_SECONDS + 2, $expires);

        [$viewStatus, $viewHeaders, $body] = $this->request('GET', '/');
        $this->assertSame(200, $viewStatus);
        $this->assertStringNotContainsString(self::TEST_SECRET, $body);

        $csrfCookie = $this->cookieValue($this->setCookie($viewHeaders, Server::CSRF_COOKIE));
        $csrfHeaders = [
            'Cookie' => Server::CSRF_COOKIE . '=' . $csrfCookie,
            'X-Appwrite-Installer-CSRF' => $csrfCookie,
            'Content-Type' => 'application/json',
        ];
        [$unauthorizedStatus] = $this->request('POST', '/install/validate', $csrfHeaders, '{}');
        $this->assertSame(401, $unauthorizedStatus);

        $csrfHeaders['Cookie'] .= '; ' . Server::SESSION_COOKIE . '=' . $sessionCookie;
        [$authorizedStatus, , $authorizedBody] = $this->request('POST', '/install/validate', $csrfHeaders, '{}');
        $this->assertSame(200, $authorizedStatus);
        $this->assertSame(['success' => true], json_decode($authorizedBody, true));

        [$scriptStatus, , $script] = $this->request('GET', '/installer/js/modules/progress.js');
        $this->assertSame(200, $scriptStatus);
        $this->assertStringContainsString("sessionStorage.removeItem('appwrite-installer-secret')", $script);
        $this->assertStringNotContainsString("sessionStorage.setItem('appwrite-installer-secret'", $script);
        $this->assertStringNotContainsString('X-Appwrite-Installer-Secret', $script);
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, array<int, string>>, 2: string}
     */
    private function request(string $method, string $path, array $headers = [], string $body = ''): array
    {
        $handle = curl_init($this->endpoint . $path);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the installer E2E request');
        }
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $name, string $value): string => $name . ': ' . $value,
                array_keys($headers),
                array_values($headers)
            ),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))][] = trim($value);
                }
                return $length;
            },
        ]);
        if ($body !== '') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        if (!is_string($responseBody)) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('Installer E2E request failed: ' . $message);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [$status, $responseHeaders, $responseBody];
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function setCookie(array $headers, string $name): string
    {
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            if (str_starts_with(strtolower($cookie), strtolower($name) . '=')) {
                return $cookie;
            }
        }

        $this->fail('Expected ' . $name . ' cookie');
    }

    private function cookieValue(string $setCookie): string
    {
        $pair = explode(';', $setCookie, 2)[0];
        return explode('=', $pair, 2)[1] ?? '';
    }

    private function cookieExpiry(string $setCookie): int
    {
        foreach (explode(';', $setCookie) as $attribute) {
            if (str_starts_with(strtolower(trim($attribute)), 'expires=')) {
                $expires = strtotime(trim(substr(trim($attribute), 8)));
                if ($expires !== false) {
                    return $expires;
                }
            }
        }

        $this->fail('Expected the installer session cookie to have an expiry');
    }
}
