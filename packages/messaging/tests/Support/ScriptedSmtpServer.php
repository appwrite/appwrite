<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Support;

/**
 * A real SMTP peer on a loopback port that says exactly what it is told to.
 *
 * It runs in a process of its own, because the client under test blocks on
 * every reply and could not answer itself. The replies are the lines production
 * servers sent, so what the adapter parses is what it parses in production.
 */
final class ScriptedSmtpServer
{
    public readonly int $port;

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    /**
     * @param  list<string>  $replies  The greeting, then the reply to each command in order.
     */
    public function __construct(array $replies)
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/smtp-server.php', base64_encode((string) json_encode($replies))],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the SMTP server');
        }

        $this->process = $process;
        $this->pipes = $pipes;

        stream_set_timeout($this->pipes[1], 10);
        $port = fgets($this->pipes[1]);

        if ($port === false || (int) $port <= 0) {
            throw new \RuntimeException('The SMTP server did not start: ' . stream_get_contents($this->pipes[2]));
        }

        $this->port = (int) $port;
    }

    /**
     * Every command the client sent, once the session is over.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        $commands = [];

        while (($line = fgets($this->pipes[1])) !== false) {
            $commands[] = (string) json_decode($line);
        }

        return $commands;
    }

    /**
     * A port nobody listens on, for the host that cannot be reached at all.
     */
    public static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new \RuntimeException('Could not reserve a port');
        }

        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    public function __destruct()
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }

        proc_terminate($this->process);
        proc_close($this->process);
    }
}
