<?php

declare(strict_types=1);

namespace Utopia\SMTP;

use Utopia\SMTP\Auth\Authenticator;
use Utopia\SMTP\Exception\AuthenticationException;
use Utopia\SMTP\Exception\CapabilityException;
use Utopia\SMTP\Exception\ConnectionException;
use Utopia\SMTP\Exception\ProtocolException;
use Utopia\SMTP\Exception\SmtpException;
use Utopia\SMTP\Exception\TransactionException;
use Utopia\SMTP\Mime\Encoding;
use Utopia\SMTP\Transport\Transport;

/**
 * An RFC 5321 client for submitting mail to a configured server.
 *
 * One instance is one connection. Reusing connections is what
 * utopia-php/pools is for, so there is no keep-alive setting here.
 */
final class Client
{
    private const int CHUNK = 8192;

    /** RFC 5321 section 4.5.3.1.5 says 512 octets. Servers are looser; this is not. */
    private const int MAX_LINE = 4096;

    private const int MAX_LINES = 128;

    /** A mechanism that keeps challenging is failing, whatever it says. */
    private const int MAX_CHALLENGES = 10;

    private bool $ready = false;

    private Capabilities $capabilities;

    /** Bytes taken off the transport but not yet consumed by a reply. */
    private string $buffer = '';

    /**
     * @param  string  $domain  The EHLO argument, naming this client to the server.
     * @param  list<Authenticator>  $authenticators  Tried in order against what the server advertises.
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $domain = 'localhost',
        private readonly array $authenticators = [],
        private readonly Encryption $encryption = Encryption::Opportunistic,
        private readonly Timeouts $timeouts = new Timeouts(),
    ) {
        $this->capabilities = Capabilities::none();
    }

    public function send(Message $message): Result
    {
        return $this->sendRaw(Envelope::fromMessage($message), $message->toIterable());
    }

    /**
     * @param  string|iterable<string>  $content  RFC 5322 bytes, which the client does not parse.
     */
    public function sendRaw(Envelope $envelope, string|iterable $content): Result
    {
        $this->start();

        $this->command("MAIL FROM:<{$envelope->sender}>" . $this->parameters($envelope, $content), [250]);

        $accepted = [];
        $rejected = [];
        $refusal = null;

        foreach ($envelope->recipients as $recipient) {
            try {
                $this->command("RCPT TO:<{$recipient}>", [250, 251, 252]);
                $accepted[] = $recipient;
            } catch (TransactionException $exception) {
                // Partial refusal is normal and the message still reaches the rest.
                $rejected[$recipient] = $exception->reply;
                $refusal ??= $exception->reply;
            }
        }

        if ($refusal instanceof \Utopia\SMTP\Reply && $accepted === []) {
            $this->reset();

            throw new TransactionException($refusal, 'Every recipient was refused');
        }

        $this->command('DATA', [354]);

        try {
            foreach ($this->stuff(\is_string($content) ? [$content] : $content) as $chunk) {
                $this->transport->write($chunk, $this->timeouts->write);
            }
        } catch (\Throwable $exception) {
            // The terminating dot never went out, so the server is still reading
            // message data and there is no longer any way to tell it otherwise.
            // Reusing this connection would send the next MAIL FROM as content.
            $this->discard();

            throw $exception;
        }

        // A refusal here is clean -- the server read the dot and is back in
        // command state -- so only a transaction failure keeps the connection.
        // exchange() decides that; a dead or desynchronised stream does not.
        return new Result($this->messageId($this->exchange([250])), $accepted, $rejected);
    }

    public function capabilities(): Capabilities
    {
        $this->start();

        return $this->capabilities;
    }

    /**
     * The escape hatch, so an unmodelled verb is never a reason to fork.
     *
     * @param  int|list<int>  $expect
     */
    public function command(string $command, int|array $expect): Reply
    {
        if (preg_match('/[\r\n]/', $command) === 1) {
            throw new \InvalidArgumentException('A command must not span lines');
        }

        return $this->exchange(
            \is_array($expect) ? $expect : [$expect],
            $command . "\r\n",
        );
    }

    /**
     * Write, then read one reply, keeping the connection only when the server
     * gave an answer.
     *
     * A 4yz or 5yz is an answer: the session continues and the caller decides.
     * A socket that failed, or a reply that cannot be parsed, means the stream
     * is dead or out of step with us — and a client that stayed ready would
     * send its next MAIL FROM into that.
     *
     * @param  list<int>  $expect
     */
    private function exchange(array $expect, string $write = ''): Reply
    {
        try {
            if ($write !== '') {
                $this->transport->write($write, $this->timeouts->write);
            }

            return $this->expect($expect);
        } catch (ConnectionException|ProtocolException $exception) {
            $this->discard();

            throw $exception;
        }
    }

    public function noop(): void
    {
        $this->command('NOOP', [250]);
    }

    public function reset(): void
    {
        $this->command('RSET', [250]);
    }

    public function close(): void
    {
        if ($this->ready) {
            try {
                $this->command('QUIT', [221]);
            } catch (SmtpException) {
                // Saying goodbye is a courtesy; the socket closes either way.
            }
        }

        $this->discard();
    }

    /**
     * Drop the connection without the courtesy of a QUIT, for when the session
     * is past saving.
     */
    private function discard(): void
    {
        $this->transport->close();
        $this->ready = false;
        $this->buffer = '';
        $this->capabilities = Capabilities::none();
    }

    private function start(): void
    {
        if ($this->ready) {
            return;
        }

        $this->transport->connect($this->timeouts->connect, $this->encryption === Encryption::Implicit);
        $this->exchange([220]);
        $this->hello();
        $this->secure();
        $this->authenticate();

        $this->ready = true;
    }

    private function hello(): void
    {
        try {
            $this->capabilities = Capabilities::fromReply($this->command("EHLO {$this->domain}", [250]));
        } catch (TransactionException) {
            // A server too old for ESMTP still has to answer HELO.
            $this->command("HELO {$this->domain}", [250]);
            $this->capabilities = Capabilities::none();
        }
    }

    private function secure(): void
    {
        if ($this->transport->isTls() || $this->encryption === Encryption::None) {
            return;
        }

        if (! $this->capabilities->has('STARTTLS')) {
            if ($this->encryption === Encryption::StartTls) {
                throw new CapabilityException('The server does not offer STARTTLS');
            }

            return;
        }

        $this->command('STARTTLS', [220]);
        $this->transport->startTls($this->timeouts->connect);

        // Anything already buffered arrived in the clear and could have been
        // injected ahead of the handshake, so it is dropped unread. The
        // capability map goes with it, per RFC 3207 section 4.2.
        $this->buffer = '';
        $this->hello();
    }

    private function authenticate(): void
    {
        if ($this->authenticators === []) {
            return;
        }

        $offered = $this->capabilities->mechanisms();

        foreach ($this->authenticators as $authenticator) {
            if (\in_array(strtoupper($authenticator->mechanism()), $offered, true)) {
                $this->attempt($authenticator);

                return;
            }
        }

        throw new AuthenticationException(
            'No shared mechanism. The server offers: ' . ($offered === [] ? 'none' : implode(', ', $offered)),
        );
    }

    private function attempt(Authenticator $authenticator): void
    {
        $initial = $authenticator->initial();
        $command = 'AUTH ' . strtoupper($authenticator->mechanism());

        if ($initial !== null) {
            // RFC 4954 spells an empty initial response as a bare equals sign.
            $command .= ' ' . ($initial === '' ? '=' : base64_encode($initial));
        }

        try {
            $reply = $this->command($command, [235, 334]);

            for ($challenges = 0; $reply->code === 334; ++$challenges) {
                if ($challenges >= self::MAX_CHALLENGES) {
                    throw new AuthenticationException('The server kept challenging');
                }

                $challenge = base64_decode($reply->text(), true);
                $reply = $this->command(
                    base64_encode($authenticator->respond($challenge === false ? '' : $challenge, $challenges)),
                    [235, 334],
                );
            }
        } catch (TransactionException $exception) {
            throw new AuthenticationException(
                "Authentication failed: {$exception->reply}",
                $exception->reply->code,
                $exception,
            );
        }
    }

    /**
     * MAIL FROM parameters, decided by what the server advertised.
     *
     * @param  string|iterable<string>  $content
     */
    private function parameters(Envelope $envelope, string|iterable $content): string
    {
        $parameters = '';

        if (\is_string($content)) {
            $size = \strlen($content);
            $max = $this->capabilities->maxSize();

            if ($max !== null && $size > $max) {
                throw new CapabilityException("The message is {$size} bytes and the server takes at most {$max}");
            }

            if ($this->capabilities->has('SIZE')) {
                $parameters .= " SIZE={$size}";
            }

            if (! Encoding::isAscii($content) && $this->capabilities->has('8BITMIME')) {
                $parameters .= ' BODY=8BITMIME';
            }
        }

        if ($envelope->isInternational()) {
            if (! $this->capabilities->has('SMTPUTF8')) {
                throw new CapabilityException('The server does not offer SMTPUTF8, so a non-ASCII address cannot be sent');
            }

            $parameters .= ' SMTPUTF8';
        }

        return $parameters;
    }

    /**
     * Normalise line endings, double any leading dot, and close with the
     * terminator.
     *
     * The state carried between chunks is the point: a chunk ending in CRLF
     * followed by one opening with a dot has to be stuffed as though it were
     * one string, and a chunk ending mid-CRLF must not be read as a bare CR.
     *
     * @param  iterable<string>  $chunks
     * @return \Generator<string>
     */
    private function stuff(iterable $chunks): \Generator
    {
        $start = true;
        $held = '';

        foreach ($chunks as $chunk) {
            $chunk = $held . $chunk;
            $held = '';

            if (str_ends_with($chunk, "\r")) {
                $held = "\r";
                $chunk = substr($chunk, 0, -1);
            }

            if ($chunk === '') {
                continue;
            }

            $chunk = Encoding::lineEndings($chunk);

            if ($start && str_starts_with($chunk, '.')) {
                $chunk = '.' . $chunk;
            }

            $chunk = str_replace("\r\n.", "\r\n..", $chunk);
            $start = str_ends_with($chunk, "\r\n");

            yield $chunk;
        }

        if ($held !== '') {
            yield "\r\n";
            $start = true;
        }

        yield ($start ? '' : "\r\n") . ".\r\n";
    }

    /**
     * @param  list<int>  $expect
     */
    private function expect(array $expect): Reply
    {
        $reply = $this->reply();

        if (\in_array($reply->code, $expect, true)) {
            return $reply;
        }

        $wanted = implode(' or ', array_map(\strval(...), $expect));

        // A refusal is the server's answer; anything else is it misbehaving.
        throw match ($reply->outcome) {
            Outcome::Transient, Outcome::Permanent => new TransactionException(
                $reply,
                "Expected {$wanted}, the server said: {$reply}",
            ),
            default => new ProtocolException("Expected {$wanted}, the server said: {$reply}"),
        };
    }

    private function reply(): Reply
    {
        $lines = [];
        $code = 0;

        do {
            $line = $this->line();

            if (preg_match('/^(\d{3})([ -]?)/', $line, $matches) !== 1) {
                throw new ProtocolException("Not a reply: {$line}");
            }

            if ($lines !== [] && (int) $matches[1] !== $code) {
                throw new ProtocolException("The reply code changed part way through: {$line}");
            }

            $code = (int) $matches[1];
            $lines[] = substr($line, 4);

            if (\count($lines) > self::MAX_LINES) {
                throw new ProtocolException('The reply has too many lines');
            }
        } while ($matches[2] === '-');

        return new Reply($code, $lines);
    }

    private function line(): string
    {
        while (($position = strpos($this->buffer, "\r\n")) === false) {
            if (\strlen($this->buffer) > self::MAX_LINE) {
                throw new ProtocolException('The reply line is too long');
            }

            $this->buffer .= $this->transport->read(self::CHUNK, $this->timeouts->read);
        }

        $line = substr($this->buffer, 0, $position);
        $this->buffer = substr($this->buffer, $position + 2);

        return $line;
    }

    /**
     * Servers report the queue identifier in the final reply, each in its own way.
     */
    private function messageId(Reply $reply): string
    {
        $patterns = [
            '/\bqueued as ([A-Za-z0-9]+)/i',
            '/\bid=(\S+)/i',
            '/\bOk ([0-9a-f-]{8,})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $reply->text(), $matches) === 1) {
                return $matches[1];
            }
        }

        return '';
    }
}
