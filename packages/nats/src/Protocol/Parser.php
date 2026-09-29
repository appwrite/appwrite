<?php

declare(strict_types=1);

namespace Utopia\NATS\Protocol;

use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Transport\Transport;

final class Parser
{
    private string $buffer = '';
    private bool $poisoned = false;

    public function __construct(
        private readonly Transport $transport,
    ) {}

    /** Whether received bytes remain to be parsed before another transport read. */
    public function hasBufferedData(): bool
    {
        return $this->buffer !== '';
    }

    /**
     * Read and parse the next server operation.
     *
     * @return array{0: ServerOp, 1: mixed} Tuple of [operation, parsed data]
     */
    public function next(?float $timeout = null): array
    {
        // Once a frame body has been partially consumed the buffer no longer
        // starts on a frame boundary, so nothing after that point can be parsed.
        if ($this->poisoned) {
            throw new ConnectionException('Parser desynced from the stream; the connection must be rebuilt');
        }

        $line = $this->readLine($timeout);
        $line = rtrim($line, "\r\n");

        if ($line === '') {
            throw new ProtocolException('Empty protocol line received');
        }

        // +OK
        if ($line === '+OK') {
            return [ServerOp::Ok, null];
        }

        // PING
        if ($line === 'PING') {
            return [ServerOp::Ping, null];
        }

        // PONG
        if ($line === 'PONG') {
            return [ServerOp::Pong, null];
        }

        // -ERR 'message'
        if (str_starts_with($line, '-ERR')) {
            $message = trim(substr($line, 4), " \t'\"");
            return [ServerOp::Err, $message];
        }

        // INFO {json}
        if (str_starts_with($line, 'INFO ')) {
            $json = substr($line, 5);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return [ServerOp::Info, $data];
        }

        // MSG <subject> <sid> [reply-to] <#bytes>
        if (str_starts_with($line, 'MSG ')) {
            return $this->readFrame(fn(): array => $this->parseMsg(substr($line, 4), $timeout));
        }

        // HMSG <subject> <sid> [reply-to] <#header-bytes> <#total-bytes>
        if (str_starts_with($line, 'HMSG ')) {
            return $this->readFrame(fn(): array => $this->parseHmsg(substr($line, 5), $timeout));
        }

        throw new ProtocolException("Unknown protocol operation: {$line}");
    }

    /**
     * Parse MSG: subject sid [reply-to] #bytes
     *
     * @return array{0: ServerOp, 1: array{subject: string, sid: string, replyTo: ?string, payload: string}}
     */
    private function parseMsg(string $args, ?float $timeout = null): array
    {
        $parts = preg_split('/\s+/', trim($args));

        if ($parts === false || \count($parts) < 3 || \count($parts) > 4) {
            throw new ProtocolException("Invalid MSG line: MSG {$args}");
        }

        if (\count($parts) === 3) {
            [$subject, $sid, $byteCount] = $parts;
            $replyTo = null;
        } else {
            [$subject, $sid, $replyTo, $byteCount] = $parts;
        }

        $bytes = (int) $byteCount;
        $payload = $this->readExactly($bytes, $timeout);
        // Consume trailing \r\n
        $this->readExactly(2, $timeout);

        return [ServerOp::Msg, [
            'subject' => $subject,
            'sid' => $sid,
            'replyTo' => $replyTo,
            'payload' => $payload,
            'headers' => null,
        ]];
    }

    /**
     * Parse HMSG: subject sid [reply-to] #header-bytes #total-bytes
     *
     * @return array{0: ServerOp, 1: array{subject: string, sid: string, replyTo: ?string, payload: string, headers: string}}
     */
    private function parseHmsg(string $args, ?float $timeout = null): array
    {
        $parts = preg_split('/\s+/', trim($args));

        if ($parts === false || \count($parts) < 4 || \count($parts) > 5) {
            throw new ProtocolException("Invalid HMSG line: HMSG {$args}");
        }

        if (\count($parts) === 4) {
            [$subject, $sid, $headerBytes, $totalBytes] = $parts;
            $replyTo = null;
        } else {
            [$subject, $sid, $replyTo, $headerBytes, $totalBytes] = $parts;
        }

        $hdrLen = (int) $headerBytes;
        $totalLen = (int) $totalBytes;
        $payloadLen = $totalLen - $hdrLen;

        if ($payloadLen < 0) {
            throw new ProtocolException("Invalid HMSG byte counts: header={$hdrLen}, total={$totalLen}");
        }

        $headerBlock = $this->readExactly($hdrLen, $timeout);
        $payload = $payloadLen > 0 ? $this->readExactly($payloadLen, $timeout) : '';
        // Consume trailing \r\n
        $this->readExactly(2, $timeout);

        return [ServerOp::HMsg, [
            'subject' => $subject,
            'sid' => $sid,
            'replyTo' => $replyTo,
            'payload' => $payload,
            'headers' => $headerBlock,
        ]];
    }

    private function readLine(?float $timeout = null): string
    {
        // Check buffer for a complete line first
        $pos = strpos($this->buffer, "\n");
        if ($pos !== false) {
            $line = substr($this->buffer, 0, $pos + 1);
            $this->buffer = substr($this->buffer, $pos + 1);
            return $line;
        }

        // Read from transport until we get a line
        while (true) {
            $data = $this->transport->read(65536, $timeout);
            $this->buffer .= $data;

            $pos = strpos($this->buffer, "\n");
            if ($pos !== false) {
                $line = substr($this->buffer, 0, $pos + 1);
                $this->buffer = substr($this->buffer, $pos + 1);
                return $line;
            }
        }
    }

    /**
     * Read exactly $bytes, passing the timeout explicitly on every transport
     * read. Previously it passed none, so the read inherited whatever deadline
     * the last readLine() happened to leave on the stream. The timeout applies
     * per read rather than to the frame as a whole: a large payload arrives
     * across several segments, and charging the whole body to one deadline
     * would fail frames that are making steady progress.
     */
    private function readExactly(int $bytes, ?float $timeout = null): string
    {
        while (\strlen($this->buffer) < $bytes) {
            $data = $this->transport->read(max(65536, $bytes - \strlen($this->buffer)), $timeout);
            if ($data === '') {
                throw new ProtocolException('Unexpected end of data while reading payload');
            }
            $this->buffer .= $data;
        }

        $result = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);
        return $result;
    }

    /**
     * Run a frame-body read, turning any failure into a poisoned parser.
     *
     * The header line is already consumed by the time this runs, so a partial
     * body read leaves the buffer mid-frame. Reporting that as a timeout would
     * be indistinguishable from "nothing arrived" -- the caller would carry on
     * against a stream whose next bytes are payload, not protocol. Raising
     * ConnectionException instead routes it to the reconnect path, which builds
     * a fresh parser. The dropped frame is often the PubAck itself.
     *
     * @param \Closure(): array{0: ServerOp, 1: mixed} $read
     * @return array{0: ServerOp, 1: mixed}
     */
    private function readFrame(\Closure $read): array
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            $this->poisoned = true;
            $this->buffer = '';

            throw new ConnectionException("Failed mid-frame, parser desynced from the stream: {$e->getMessage()}", $e->getCode(), previous: $e);
        }
    }
}
