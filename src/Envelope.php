<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * Where the bytes go, which is not the same question as what the headers say.
 *
 * A blind recipient belongs here and nowhere else: Envelope::fromMessage puts
 * it in RCPT TO, and the renderer leaves it out of the headers.
 */
final readonly class Envelope
{
    /**
     * @param  string  $sender  The reverse-path. Empty for a bounce.
     * @param  list<string>  $recipients  Forward-paths.
     * @param  bool  $utf8  Ask for SMTPUTF8 even when every path is ASCII, for a
     *                      message whose headers still need it.
     */
    public function __construct(
        public string $sender,
        public array $recipients,
        private bool $utf8 = false,
    ) {
        if ($recipients === []) {
            throw new \InvalidArgumentException('An envelope needs at least one recipient');
        }

        foreach ([$sender, ...$recipients] as $path) {
            if (preg_match('/[\r\n\x00]/', $path) === 1) {
                throw new \InvalidArgumentException('A path must not span lines');
            }
        }
    }

    public static function fromMessage(Message $message): self
    {
        $recipients = [];

        foreach ([...$message->to, ...$message->cc, ...$message->bcc] as $address) {
            $recipients[$address->email] = true;
        }

        // Reply-To is a header and never a path, so a non-ASCII one shows up
        // nowhere in this list — but it still needs the extension to be sent.
        return new self($message->from->email, array_keys($recipients), $message->isInternational());
    }

    /**
     * Whether this transaction needs the SMTPUTF8 extension of RFC 6531.
     */
    public function isInternational(): bool
    {
        if ($this->utf8) {
            return true;
        }

        foreach ([$this->sender, ...$this->recipients] as $path) {
            if (preg_match('/^[\x00-\x7F]*$/', $path) !== 1) {
                return true;
            }
        }

        return false;
    }
}
