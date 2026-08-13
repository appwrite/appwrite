<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit\Support;

use Utopia\SMTP\ConnectionException;
use Utopia\SMTP\Transport\Transport;

/**
 * A scripted server. Replies are queued up front and handed out a line at a
 * time; everything the client writes is kept for inspection.
 */
class FakeTransport implements Transport
{
    public string $written = '';

    public bool $connected = false;

    public bool $closed = false;

    public int $handshakes = 0;

    private string $pending = '';

    private bool $tls = false;

    /**
     * @param  list<string>  $replies  Reply lines, without their line endings.
     * @param  bool  $greedy  Hand over everything queued at once, the way a server
     *                        that pipelines — or an attacker injecting ahead of a
     *                        handshake — would.
     * @param  list<string>  $afterHandshake  Queued once TLS is up, since a real
     *                                        server says nothing more until then.
     * @param  string|null  $failWriting  Fail the write that carries this text,
     *                                    to model a connection dropping mid-send.
     * @param  int  $chunk  Never hand over more than this many bytes at once, for
     *                      a server whose reply arrives in pieces.
     */
    public function __construct(
        array $replies = [],
        private readonly bool $failHandshake = false,
        private readonly bool $greedy = false,
        private readonly array $afterHandshake = [],
        private readonly ?string $failWriting = null,
        private readonly int $chunk = PHP_INT_MAX,
    ) {
        $this->reply(...$replies);
    }

    /**
     * Queue more lines, for a script that grows as the exchange goes on.
     */
    public function reply(string ...$lines): void
    {
        foreach ($lines as $line) {
            $this->pending .= $line . "\r\n";
        }
    }

    public function connect(float $timeout, bool $tls): void
    {
        $this->connected = true;
        $this->tls = $tls;
    }

    public function read(int $length, float $timeout): string
    {
        if ($this->pending === '') {
            throw new ConnectionException('The server closed the connection');
        }

        $end = strpos($this->pending, "\r\n");
        $take = $this->greedy || $end === false ? $length : min($length, $end + 2);
        $take = min($take, $this->chunk);

        $chunk = substr($this->pending, 0, $take);
        $this->pending = substr($this->pending, \strlen($chunk));

        return $chunk;
    }

    public function write(string $data, float $timeout): void
    {
        if ($this->failWriting !== null && str_contains($data, $this->failWriting)) {
            throw new ConnectionException('The connection dropped mid-write');
        }

        $this->written .= $data;
    }

    public function startTls(float $timeout): void
    {
        if ($this->failHandshake) {
            throw new ConnectionException('Handshake failed');
        }

        ++$this->handshakes;
        $this->tls = true;
        $this->reply(...$this->afterHandshake);
    }

    public function isTls(): bool
    {
        return $this->tls;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * What the client sent, split into lines.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return array_values(array_filter(
            explode("\r\n", $this->written),
            static fn(string $line): bool => $line !== '',
        ));
    }
}
