<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Support;

/**
 * Drives tests/fixtures/peer.php: the server end of one connection in its own
 * process, so a client blocked on a TLS or WebSocket handshake can complete it.
 */
final class Peer
{
    private const float ANSWER_TIMEOUT = 10.0;

    public readonly int $port;

    /** @var resource */
    private $process;

    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    public function __construct(?string $certificates = null, bool $websocket = false)
    {
        $command = [PHP_BINARY, \dirname(__DIR__) . '/fixtures/peer.php'];
        if ($certificates !== null) {
            $command[] = "--certificates={$certificates}";
        }
        if ($websocket) {
            $command[] = '--websocket';
        }

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the peer process');
        }
        $this->process = $process;
        [$this->input, $this->output] = $pipes;

        $this->port = (int) $this->answer();
    }

    /** Wait for the peer to finish its side of the handshake. */
    public function accept(): void
    {
        $this->expect('ready');
    }

    public function send(string $bytes): void
    {
        $this->command('send ' . base64_encode($bytes));
    }

    public function close(): void
    {
        $this->command('close');
    }

    /**
     * End the process with SO_LINGER 0 set, so the client gets a reset instead
     * of a FIN, and over TLS no close_notify ahead of it.
     */
    public function reset(): void
    {
        $this->command('linger');
        proc_terminate($this->process);
        $this->stop();
    }

    public function stop(): void
    {
        if (\is_resource($this->input)) {
            fclose($this->input);
        }
        if (\is_resource($this->output)) {
            fclose($this->output);
        }
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    private function command(string $line): void
    {
        fwrite($this->input, "{$line}\n");
        $this->expect('ok');
    }

    private function expect(string $answer): void
    {
        $received = $this->answer();
        if ($received !== $answer) {
            throw new \RuntimeException("The peer answered '{$received}', expected '{$answer}'");
        }
    }

    private function answer(): string
    {
        stream_set_timeout($this->output, (int) self::ANSWER_TIMEOUT);
        $line = fgets($this->output);
        if ($line === false) {
            throw new \RuntimeException('The peer process stopped answering');
        }

        return trim($line);
    }
}
