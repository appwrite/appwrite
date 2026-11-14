<?php

namespace Appwrite\Alert\Probe;

use Appwrite\Alert\Probe;
use Utopia\Database\Document;
use Utopia\System\System;

/**
 * Reads the banner, sends EHLO and, as the monitor's `tlsMode` asks, upgrades
 * with STARTTLS (and greets again) or speaks TLS from the first byte, then
 * sends QUIT without waiting for its reply. It never authenticates or sends
 * mail, so a server that accepts connections but refuses service fails here
 * where a TCP monitor would pass.
 */
final readonly class SMTP implements Probe
{
    public const string TLS_NONE = 'none';

    public const string TLS_STARTTLS = 'starttls';

    public const string TLS_IMPLICIT = 'implicit';

    /** The longest reply line a server may send, line ending included (RFC 5321 section 4.5.3.1.5). */
    private const int LINE = 512;

    /**
     * Lines in one reply. RFC 5321 sets no count; the longest reply read here is
     * EHLO's, a line per extension, and 128 is several times the extensions
     * registered. With the line limit it bounds a reply to 64 KiB.
     */
    private const int LINES = 128;

    /**
     * The mode a port implies when none is chosen: implicit TLS on 465 (RFC 8314),
     * STARTTLS on submission (587), plaintext otherwise.
     */
    public static function mode(int $port): string
    {
        return match ($port) {
            465 => self::TLS_IMPLICIT,
            587 => self::TLS_STARTTLS,
            default => self::TLS_NONE,
        };
    }

    public function check(Document $monitor, Egress $egress): Check
    {
        $start = \hrtime(true);
        $deadline = \microtime(true) + (int) $monitor->getAttribute('timeout', 10);
        $port = (int) $monitor->getAttribute('port');
        $mode = (string) ($monitor->getAttribute('tlsMode') ?: self::mode($port));
        // The name this server announces itself by.
        $hello = 'EHLO ' . System::getEnv('_APP_DOMAIN', 'localhost');
        $code = null;
        $expiry = null;

        try {
            $connection = Connection::open((string) $monitor->getAttribute('host'), $port, $mode === self::TLS_IMPLICIT, true, $egress, $deadline);

            try {
                if ($mode === self::TLS_IMPLICIT) {
                    $expiry = $connection->certificateExpiresAt();
                }

                [$code] = $this->reply($connection, $code);
                $connection->write($hello . "\r\n");
                [$code, $extensions] = $this->reply($connection, $code);

                if ($mode === self::TLS_STARTTLS) {
                    // A server that stops offering STARTTLS answered every command, but
                    // mail to it would travel in plaintext: a TLS failure, not a refusal.
                    if (!$this->advertises($extensions, 'STARTTLS')) {
                        throw new Failure(Result::TlsFailed, $code);
                    }
                    $connection->write("STARTTLS\r\n");
                    [$code] = $this->reply($connection, $code);
                    $connection->startTls();
                    $expiry = $connection->certificateExpiresAt();
                    // RFC 3207 discards the session's state on the upgrade, so the server greets again.
                    $connection->write($hello . "\r\n");
                    [$code] = $this->reply($connection, $code);
                }

                // A polite close; a server slow to say goodbye does not stretch the check.
                $connection->write("QUIT\r\n");
            } finally {
                $connection->close();
            }
        } catch (Failure $failure) {
            return new Check($failure->result, (int) \round((\hrtime(true) - $start) / 1e6), $failure->reply ?? $code, $expiry);
        }

        return new Check(Result::Ok, (int) \round((\hrtime(true) - $start) / 1e6), $code, $expiry);
    }

    /**
     * Reads one reply within the RFC 5321 limits and requires it to be 2xx.
     *
     * @return array{0: int, 1: list<string>} its code and the text of each line
     *
     * @throws Failure
     */
    private function reply(Connection $connection, ?int $last): array
    {
        $code = null;
        $lines = [];

        for ($count = 0; $count < self::LINES; $count++) {
            try {
                $line = $connection->line(self::LINE);
            } catch (\LengthException) {
                throw new Failure(Result::SmtpRejected, $code ?? $last);
            }

            $prefix = \substr($line, 0, 3);
            if (\strlen($prefix) !== 3 || !\ctype_digit($prefix) || ($code !== null && (int) $prefix !== $code)) {
                throw new Failure(Result::SmtpRejected, $code ?? $last);
            }

            $code = (int) $prefix;
            $lines[] = \substr($line, 4);

            if (($line[3] ?? ' ') !== '-') {
                if ($code < 200 || $code > 299) {
                    throw new Failure(Result::SmtpRejected, $code);
                }

                return [$code, $lines];
            }
        }

        throw new Failure(Result::SmtpRejected, $code);
    }

    /**
     * Whether an EHLO reply lists the extension. Its first line greets, it lists nothing.
     *
     * @param list<string> $lines
     */
    private function advertises(array $lines, string $extension): bool
    {
        foreach (\array_slice($lines, 1) as $line) {
            if (\strcasecmp(\strtok($line, ' ') ?: '', $extension) === 0) {
                return true;
            }
        }

        return false;
    }
}
