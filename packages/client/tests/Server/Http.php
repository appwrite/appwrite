<?php

declare(strict_types=1);

namespace Utopia\Tests\Server;

use RuntimeException;

final class Http
{
    /**
     * Run the routed test server (server.php) for the duration of the callable.
     * The server is started, awaited until ready, and torn down automatically.
     *
     * @param callable(int): void $test receives the listening port
     */
    public static function serve(callable $test): void
    {
        $port = self::availablePort();
        $server = self::start($port);

        try {
            $test($port);
        } finally {
            self::stop($server);
        }
    }

    /**
     * Run a server that replies with $response verbatim to a single request,
     * for the duration of the callable. Started, awaited, and torn down for you.
     *
     * @param callable(int): void $test receives the listening port
     */
    public static function raw(string $response, callable $test): void
    {
        $port = self::availablePort();
        $server = self::startRaw($port, $response);

        try {
            $test($port);
        } finally {
            self::stop($server);
        }
    }

    /**
     * Run a server that closes its first connection right after answering one
     * request — an idle keep-alive socket dropped by the peer — while every
     * later connection is kept alive and answers repeatedly. Returns the number
     * of connections accepted, so a caller can confirm a reusing client had to
     * re-establish the dropped connection rather than open one per request.
     * With $dropResponse, read the second request completely and close without
     * answering it, so tests can detect an unsafe replay after a send.
     *
     * @param callable(int, string): void $test receives the listening port and TLS certificate path
     * @param list<string>|null $receivedRequests receives the HTTP request headers observed by the server
     * @param-out list<string> $receivedRequests
     *
     * @return int connections the server accepted
     */
    public static function dropsFirstKeepAliveConnection(callable $test, bool $tls = false, bool $dropResponse = false, string $peerName = '127.0.0.1', ?array &$receivedRequests = null): int
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'utopia-drop-ready-');
        $countFile = tempnam(sys_get_temp_dir(), 'utopia-drop-count-');

        if ($readyFile === false || $countFile === false) {
            throw new RuntimeException('Unable to create drop server temp files.');
        }

        unlink($readyFile);

        $certificateFile = '';
        if ($tls) {
            $certificateFile = tempnam(sys_get_temp_dir(), 'utopia-tls-');
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            if ($certificateFile === false || $key === false) {
                throw new RuntimeException('Unable to create the TLS fixture key.');
            }

            $csr = openssl_csr_new(['commonName' => $peerName], $key);
            if (!$csr instanceof \OpenSSLCertificateSigningRequest || !$key instanceof \OpenSSLAsymmetricKey) {
                throw new RuntimeException('Unable to create the TLS fixture CSR.');
            }

            $certificate = openssl_csr_sign($csr, null, $key, 1);
            if ($certificate === false) {
                throw new RuntimeException('Unable to sign the TLS fixture certificate.');
            }

            if (!openssl_x509_export($certificate, $pem) || !openssl_pkey_export($key, $privateKey)
                || !\is_string($pem) || !\is_string($privateKey)) {
                throw new RuntimeException('Unable to export the TLS fixture certificate.');
            }

            file_put_contents($certificateFile, $pem . $privateKey);
        }

        $port = self::availablePort();

        $code = <<<'PHP'
            $port = (int) $argv[1];
            $readyFile = $argv[2];
            $countFile = $argv[3];
            $certificateFile = $argv[4];
            $tls = $certificateFile !== '';
            $dropResponse = $argv[5] === '1';
            $requests = 0;
            $context = stream_context_create(['ssl' => ['local_cert' => $certificateFile, 'verify_peer' => false]]);
            $server = stream_socket_server(($tls ? 'tls' : 'tcp') . '://127.0.0.1:' . $port, $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
            if (!is_resource($server)) {
                fwrite(STDERR, $errorCode . ' ' . $errorMessage);
                exit(1);
            }
            file_put_contents($readyFile, 'ready');
            $connections = 0;
            $receivedRequests = [];
            while (true) {
                $connection = @stream_socket_accept($server, 30);
                if (!is_resource($connection)) {
                    continue;
                }
                $connections++;
                file_put_contents($countFile, json_encode(['connections' => $connections, 'requests' => $receivedRequests]));
                $dropAfterResponse = ($connections === 1);
                while (true) {
                    $request = '';
                    while (($line = fgets($connection, 8192)) !== false) {
                        $request .= $line;
                        if ($line === "\r\n" || $line === "\n") {
                            break;
                        }
                    }
                    if ($request === '') {
                        break;
                    }
                    $length = preg_match('/Content-Length:\s*(\d+)/i', $request, $match) ? (int) $match[1] : 0;
                    $body = '';
                    while (strlen($body) < $length) {
                        $chunk = fread($connection, $length - strlen($body));
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $body .= $chunk;
                    }
                    $requests++;
                    $receivedRequests[] = $request;
                    file_put_contents($countFile, json_encode(['connections' => $connections, 'requests' => $receivedRequests]));
                    if ($dropAfterResponse && $dropResponse && $requests === 2) {
                        stream_socket_shutdown($connection, STREAM_SHUT_RDWR);
                        break;
                    }
                    $body = $body === '' ? 'ok' : $body;
                    @fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
                    if ($dropAfterResponse && !$dropResponse) {
                        // Close TCP without TLS close_notify, as an idle peer can disappear.
                        stream_socket_shutdown($connection, STREAM_SHUT_RDWR);
                        break;
                    }
                }
                @fclose($connection);
            }
            PHP;

        $server = proc_open(
            [\PHP_BINARY, '-r', $code, (string) $port, $readyFile, $countFile, $certificateFile, $dropResponse ? '1' : '0'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($server)) {
            throw new RuntimeException('Unable to start the drop test server.');
        }

        unset($pipes);
        self::waitForReadyFile($readyFile);

        try {
            $test($port, $certificateFile);
        } finally {
            self::stop($server);
            /** @var array{connections: int, requests: list<string>} $observed */
            $observed = json_decode((string) file_get_contents($countFile), true) ?: ['connections' => 0, 'requests' => []];
            $count = $observed['connections'];
            $receivedRequests = $observed['requests'];
            @unlink($countFile);
            if ($certificateFile !== '') {
                @unlink($certificateFile);
            }
        }

        return $count;
    }

    /**
     * Run the callable against a port with nothing listening on it, so a
     * connection attempt is refused. No server is started or torn down.
     *
     * @param callable(int): void $test receives the unbound port
     */
    public static function unbound(callable $test): void
    {
        $test(self::availablePort());
    }

    private static function availablePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if (!\is_resource($server)) {
            throw new RuntimeException('Unable to find an available TCP port: ' . $errorCode . ' ' . $errorMessage);
        }

        $name = stream_socket_get_name($server, false);

        fclose($server);

        if ($name === false) {
            throw new RuntimeException('Unable to read TCP port.');
        }

        $port = parse_url('tcp://' . $name, PHP_URL_PORT);

        if (!\is_int($port)) {
            throw new RuntimeException('Unable to parse TCP port.');
        }

        return $port;
    }

    /**
     * @return resource
     */
    private static function start(int $port): mixed
    {
        $server = proc_open(
            [\PHP_BINARY, '-d', 'post_max_size=64M', '-d', 'upload_max_filesize=64M', '-d', 'memory_limit=256M', '-S', '127.0.0.1:' . $port, \dirname(__DIR__) . '/server.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!\is_resource($server)) {
            throw new RuntimeException('Unable to start PHP test server.');
        }

        unset($pipes);
        self::waitForPort($port);

        return $server;
    }

    /**
     * @return resource
     */
    private static function startRaw(int $port, string $response): mixed
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'utopia-raw-server-');

        if ($readyFile === false) {
            throw new RuntimeException('Unable to create raw response server readiness file.');
        }

        unlink($readyFile);

        $code = <<<'PHP'
$port = (int) $argv[1];
$response = base64_decode($argv[2]);
$readyFile = $argv[3];
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage);
if (!is_resource($server)) {
    fwrite(STDERR, $errorCode . ' ' . $errorMessage);
    exit(1);
}
file_put_contents($readyFile, 'ready');
$connection = @stream_socket_accept($server, 10);
if (is_resource($connection)) {
    fread($connection, 8192);
    fwrite($connection, $response);
    fclose($connection);
}
fclose($server);
PHP;

        $server = proc_open(
            [\PHP_BINARY, '-r', $code, (string) $port, base64_encode($response), $readyFile],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (!\is_resource($server)) {
            throw new RuntimeException('Unable to start raw response test server.');
        }

        unset($pipes);
        self::waitForReadyFile($readyFile);

        return $server;
    }

    /**
     * @param resource $server
     */
    private static function stop(mixed $server): void
    {
        proc_terminate($server);
        proc_close($server);
    }

    private static function waitForPort(int $port): void
    {
        $deadline = microtime(true) + 5;

        do {
            $connection = @fsockopen('127.0.0.1', $port);

            if (\is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('PHP test server did not start.');
    }

    private static function waitForReadyFile(string $readyFile): void
    {
        $deadline = microtime(true) + 5;

        do {
            if (is_file($readyFile)) {
                unlink($readyFile);

                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Raw response test server did not start.');
    }
}
