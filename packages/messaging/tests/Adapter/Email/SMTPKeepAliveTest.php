<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Messages\Email;

/**
 * A kept SMTP session the server has already closed. The next command fails,
 * and that message has to go out on a new connection instead of being dropped.
 */
final class SMTPKeepAliveTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $log = '';

    private string $script = '';

    protected function tearDown(): void
    {
        $this->stop();

        foreach ([$this->log, $this->script] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRetriesAKeptSessionTheServerClosedWith421(): void
    {
        $adapter = $this->listen('421-reuse');

        $this->assertSame(1, $this->send($adapter)['deliveredTo']);

        $again = $this->send($adapter);

        $this->assertSame(1, $again['deliveredTo'], $this->detail($again));
        $this->assertSame('', $again['results'][0]['error']);
        $this->assertSame(['connection', 'delivered', '421', 'connection', 'delivered'], $this->trace());
    }

    public function testRetriesAKeptSessionWhoseSocketClosed(): void
    {
        $adapter = $this->listen('drop');

        $this->assertSame(1, $this->send($adapter)['deliveredTo']);

        $again = $this->send($adapter);

        $this->assertSame(1, $again['deliveredTo'], $this->detail($again));
        $this->assertSame(['connection', 'delivered', 'drop', 'connection', 'delivered'], $this->trace());
    }

    public function testDoesNotRetryWhenTheLiveSessionRefusesTheSender(): void
    {
        $adapter = $this->listen('550');

        $this->assertSame(1, $this->send($adapter)['deliveredTo']);

        $again = $this->send($adapter);

        $this->assertSame(0, $again['deliveredTo']);
        $this->assertStringContainsString('550', (string) $again['results'][0]['error']);
        $this->assertSame(['connection', 'delivered', '550'], $this->trace());
    }

    public function testDoesNotRetryWhenAFreshConnectionIsClosed(): void
    {
        $adapter = $this->listen('421-fresh');

        $response = $this->send($adapter);

        $this->assertSame(0, $response['deliveredTo']);
        $this->assertStringContainsString('421', (string) $response['results'][0]['error']);
        $this->assertSame(['connection', '421'], $this->trace());
    }

    public function testDoesNotRetryWhenTheReplyAfterTheDotIsLost(): void
    {
        $adapter = $this->listen('drop-after-dot');

        $this->assertSame(1, $this->send($adapter)['deliveredTo']);

        $again = $this->send($adapter);

        $this->assertSame(0, $again['deliveredTo'], $this->detail($again));
        $this->assertSame(['connection', 'delivered', 'dot'], $this->trace());
    }

    public function testSurfacesTheErrorWhenTheReplacementConnectionAlsoCloses(): void
    {
        $adapter = $this->listen('421-twice');

        $this->assertSame(1, $this->send($adapter)['deliveredTo']);

        $again = $this->send($adapter);

        $this->assertSame(0, $again['deliveredTo'], $this->detail($again));
        $this->assertStringContainsString('421', (string) $again['results'][0]['error']);
        $this->assertSame(['connection', 'delivered', '421', 'connection', '421'], $this->trace());
    }

    private function listen(string $mode): SMTP
    {
        $this->log = tempnam(sys_get_temp_dir(), 'smtp-log-');
        $this->assertNotFalse($this->log);

        $script = tempnam(sys_get_temp_dir(), 'smtp-server-');
        $this->assertNotFalse($script);
        $this->script = $script;
        file_put_contents($script, $this->server());

        $this->process = proc_open(
            [PHP_BINARY, $script, $mode, $this->log],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->pipes,
        );
        $this->assertIsResource($this->process);

        $ready = [$this->pipes[1]];
        $write = null;
        $except = null;
        if (stream_select($ready, $write, $except, 5) !== 1) {
            $this->fail('SMTP server did not start: ' . stream_get_contents($this->pipes[2]));
        }

        $port = (int) fgets($this->pipes[1]);
        $this->assertGreaterThan(0, $port);

        return new SMTP(
            host: '127.0.0.1',
            port: $port,
            timeout: 3,
            keepAlive: true,
            timelimit: 3,
            pingThreshold: 60,
        );
    }

    /**
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     */
    private function send(SMTP $adapter): array
    {
        return $adapter->send(new Email(
            to: ['tester@localhost.test'],
            subject: 'Keepalive',
            content: 'Body',
            fromName: 'Test',
            fromEmail: 'sender@localhost.test',
        ));
    }

    /**
     * @return list<string>
     */
    private function trace(): array
    {
        $lines = file($this->log, FILE_IGNORE_NEW_LINES);

        $this->assertIsArray($lines);

        return $lines;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function detail(array $response): string
    {
        return json_encode($response) . ' trace=' . implode(',', $this->trace());
    }

    private function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->process = null;

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];
    }

    private function server(): string
    {
        return <<<'PHP'
            <?php

            $listen = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($listen === false) {
                fwrite(STDERR, $errstr . "\n");
                exit(1);
            }

            $address = stream_socket_get_name($listen, false);
            $port = (int) substr((string) $address, (int) strrpos((string) $address, ':') + 1);
            fwrite(STDOUT, $port . "\n");
            fflush(STDOUT);

            $mode = $argv[1];
            $log = $argv[2];
            $deadline = time() + 30;
            $connection = 0;

            while (time() < $deadline) {
                $read = [$listen];
                $write = null;
                $except = null;

                if (stream_select($read, $write, $except, 2) !== 1) {
                    continue;
                }

                $peer = stream_socket_accept($listen, 0);
                if ($peer === false) {
                    continue;
                }

                $connection++;
                file_put_contents($log, "connection\n", FILE_APPEND);
                serve($peer, $mode, $log, $connection);
                fclose($peer);
            }

            fclose($listen);

            function serve($peer, string $mode, string $log, int $connection): void
            {
                stream_set_timeout($peer, 5);
                stream_set_write_buffer($peer, 0);
                fwrite($peer, "220 localhost ESMTP\r\n");

                $transactions = 0;
                $data = false;

                while (true) {
                    $line = fgets($peer);
                    if ($line === false) {
                        return;
                    }

                    if ($data) {
                        if ($line === ".\r\n") {
                            $data = false;

                            if ($mode === 'drop-after-dot' && $transactions >= 1) {
                                file_put_contents($log, "dot\n", FILE_APPEND);
                                return;
                            }

                            $transactions++;
                            file_put_contents($log, "delivered\n", FILE_APPEND);
                            fwrite($peer, "250 OK\r\n");
                        }

                        continue;
                    }

                    $verb = strtoupper(substr($line, 0, (int) strcspn($line, " \r\n")));

                    if ($verb === 'EHLO' || $verb === 'HELO' || $verb === 'RSET' || $verb === 'NOOP') {
                        fwrite($peer, "250 localhost\r\n");
                        continue;
                    }

                    if ($verb === 'QUIT') {
                        fwrite($peer, "221 Bye\r\n");
                        return;
                    }

                    if ($verb === 'MAIL') {
                        $close = $mode === '421-fresh'
                            || ($mode === '421-reuse' && $transactions >= 1)
                            || ($mode === '421-twice' && ($connection > 1 || $transactions >= 1))
                            || ($mode === 'drop' && $transactions >= 1);

                        if ($close && $mode === 'drop') {
                            file_put_contents($log, "drop\n", FILE_APPEND);
                            return;
                        }

                        if ($close) {
                            file_put_contents($log, "421\n", FILE_APPEND);
                            fwrite($peer, "421 Timeout - closing connection\r\n");
                            return;
                        }

                        if ($mode === '550' && $transactions >= 1) {
                            file_put_contents($log, "550\n", FILE_APPEND);
                            fwrite($peer, "550 mailbox unavailable\r\n");
                            continue;
                        }

                        fwrite($peer, "250 OK\r\n");
                        continue;
                    }

                    if ($verb === 'RCPT') {
                        fwrite($peer, "250 OK\r\n");
                        continue;
                    }

                    if ($verb === 'DATA') {
                        fwrite($peer, "354 Go ahead\r\n");
                        $data = true;
                        continue;
                    }

                    fwrite($peer, "500 unknown\r\n");
                }
            }
            PHP;
    }
}
