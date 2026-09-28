<?php

namespace Utopia\Messaging\Messages;

use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Message;
use Utopia\Messaging\Messages\Email\Attachment;

class Email implements Message
{
    /** Headers the message already writes itself; a caller value would collide at send time. */
    private const array RESERVED_HEADERS = [
        'date', 'from', 'to', 'cc', 'bcc', 'reply-to',
        'subject', 'message-id', 'mime-version',
        'content-type', 'content-transfer-encoding',
    ];

    private ?string $origin = null;

    /**
     * @var array<array<string,string>>
     */
    private readonly array $to;

    /**
     * @var array<array<string,string>>|null
     */
    private readonly ?array $cc;

    /**
     * @var array<array<string,string>>|null
     */
    private readonly ?array $bcc;

    /**
     * @param  array<string|array<string,string>>  $to The recipients of the email. Each entry can be an email string or an associative array with 'email' and optional 'name' keys.
     * @param  string  $subject The subject of the email.
     * @param  string  $content The content of the email.
     * @param  string  $fromName The name of the sender.
     * @param  string  $fromEmail The email address of the sender.
     * @param  string|null  $replyToName The name of the reply to.
     * @param  string|null  $replyToEmail The email address of the reply to.
     * @param  array<string|array<string,string>>|null  $cc The CC recipients of the email. Same format as $to.
     * @param  array<string|array<string,string>>|null  $bcc The BCC recipients of the email. Same format as $to.
     * @param  array<Attachment>|null  $attachments The attachments of the email.
     * @param  bool  $html Whether the message is HTML or not.
     * @param  array<string, string>  $headers Extra headers written as given, e.g. List-Unsubscribe. Sent to every recipient of this message; a per-recipient value needs one Email per recipient.
     */
    public function __construct(
        array $to,
        private readonly string $subject,
        private readonly string $content,
        private readonly string $fromName,
        private readonly string $fromEmail,
        private ?string $replyToName = null,
        private ?string $replyToEmail = null,
        ?array $cc = null,
        ?array $bcc = null,
        private readonly ?array $attachments = null,
        private readonly bool $html = false,
        private readonly array $headers = [],
    ) {
        $this->to = array_map($this->normalizeRecipient(...), $to);
        $this->cc = \is_null($cc) ? null : array_map($this->normalizeRecipient(...), $cc);
        $this->bcc = \is_null($bcc) ? null : array_map($this->normalizeRecipient(...), $bcc);

        if (\is_null($this->replyToName)) {
            $this->replyToName = $this->fromName;
        }

        if (\is_null($this->replyToEmail)) {
            $this->replyToEmail = $this->fromEmail;
        }

        $this->assertAddress($this->fromEmail, InvalidArgumentException::SENDER_MALFORMED);
        $this->assertName($this->fromName);
        $this->assertName($this->replyToName);

        // An explicitly empty reply-to tells the adapters to omit the header.
        if (!\in_array($this->replyToEmail, ['', '0'], true)) {
            $this->assertAddress($this->replyToEmail, InvalidArgumentException::SENDER_MALFORMED);
        }

        $seenHeaders = [];
        foreach ($this->headers as $name => $value) {
            $this->assertHeader($name, $value);

            // Two spellings of the same header would silently pick whichever the adapter sends last.
            $key = strtolower((string) $name);
            if (isset($seenHeaders[$key])) {
                throw new InvalidArgumentException(InvalidArgumentException::HEADER_MALFORMED, "Header \"{$name}\" duplicates \"{$seenHeaders[$key]}\" by case.", (string) $name);
            }
            $seenHeaders[$key] = $name;
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertAddress(string $email, string $type = InvalidArgumentException::RECIPIENT_MALFORMED): void
    {
        if ($email === '') {
            throw new InvalidArgumentException(
                $type === InvalidArgumentException::SENDER_MALFORMED ? $type : InvalidArgumentException::RECIPIENT_EMPTY,
                'Email address must not be empty.',
                $email,
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false) {
            throw new InvalidArgumentException($type, "Email address \"{$email}\" is not a valid address.", $email);
        }

        // A TLD no registry hands out (one letter, digits) can never receive mail.
        $tld = substr($email, (int) strrpos($email, '.') + 1);
        if (preg_match('/^(?:xn--[a-z0-9-]+|[a-z]{2,})$/i', $tld) !== 1) {
            throw new InvalidArgumentException(
                $type === InvalidArgumentException::SENDER_MALFORMED ? $type : InvalidArgumentException::RECIPIENT_DOMAIN_INVALID,
                "Email address \"{$email}\" has a domain that cannot receive mail.",
                $email,
            );
        }
    }

    /**
     * A line break in a display name ends the header early; other specials
     * are quoted by the adapter.
     *
     * @throws InvalidArgumentException
     */
    private function assertName(string $name): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new InvalidArgumentException(InvalidArgumentException::NAME_MALFORMED, 'Display name must not contain control characters.', $name);
        }
    }

    /**
     * A line break in a header lets the rest of the value forge headers of its own.
     *
     * @throws InvalidArgumentException
     */
    private function assertHeader(mixed $name, mixed $value): void
    {
        if (!\is_string($name) || preg_match('/^[!-9;-~]+$/', $name) !== 1) {
            throw new InvalidArgumentException(InvalidArgumentException::HEADER_MALFORMED, 'Header name must be printable ASCII without spaces or colons.', \is_string($name) ? $name : null);
        }

        if (\in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
            throw new InvalidArgumentException(InvalidArgumentException::HEADER_MALFORMED, "The message already owns the \"{$name}\" header.", $name);
        }

        if (!\is_string($value) || preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new InvalidArgumentException(InvalidArgumentException::HEADER_MALFORMED, "Header \"{$name}\" must be a single-line string.", $name);
        }

        // The MIME writer drops empty fields while the API adapters send them, so one value would vary by adapter.
        if (trim($value) === '') {
            throw new InvalidArgumentException(InvalidArgumentException::HEADER_MALFORMED, "Header \"{$name}\" must not be empty.", $name);
        }
    }

    /**
     * Normalize a recipient entry to an associative array with 'email' and optional 'name' keys.
     *
     * @param  string|array<string,string>  $value
     * @return array<string,string>
     */
    private function normalizeRecipient(string|array $value): array
    {
        if (\is_string($value)) {
            $this->assertAddress($value);

            return ['email' => $value];
        }

        if (!isset($value['email']) || $value['email'] === '') {
            throw new InvalidArgumentException(
                InvalidArgumentException::RECIPIENT_EMPTY,
                'Each recipient must have a non-empty "email" key.',
            );
        }

        $this->assertAddress($value['email']);
        $this->assertName($value['name'] ?? '');

        return $value;
    }

    /**
     * @return array<array<string,string>>
     */
    public function getTo(): array
    {
        return $this->to;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    public function getReplyToName(): string
    {
        return $this->replyToName;
    }

    public function getReplyToEmail(): string
    {
        return $this->replyToEmail;
    }

    /**
     * @return array<array<string,string>>|null
     */
    public function getCC(): ?array
    {
        return $this->cc;
    }

    /**
     * @return array<array<string,string>>|null
     */
    public function getBCC(): ?array
    {
        return $this->bcc;
    }

    /**
     * @return array<Attachment>|null
     */
    public function getAttachments(): ?array
    {
        return $this->attachments;
    }

    public function isHtml(): bool
    {
        return $this->html;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setOrigin(?string $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    public function getOrigin(): ?string
    {
        return $this->origin;
    }
}
