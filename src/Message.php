<?php

declare(strict_types=1);

namespace Utopia\SMTP;

use Utopia\SMTP\Mime\Header;
use Utopia\SMTP\Mime\Part;

/**
 * An RFC 5322 message, with the MIME structure chosen from what was set rather
 * than picked by the caller.
 */
final readonly class Message implements \Stringable
{
    /** Headers the message owns, which a caller must not set by hand. */
    private const array RESERVED = [
        'date', 'from', 'to', 'cc', 'bcc', 'reply-to',
        'subject', 'message-id', 'mime-version',
        'content-type', 'content-transfer-encoding',
    ];

    public \DateTimeImmutable $date;

    public string $messageId;

    private Part $root;

    /**
     * @param  list<Address>  $to
     * @param  list<Address>  $cc
     * @param  list<Address>  $bcc  Carried in the envelope, never written to a header.
     * @param  list<Address>  $replyTo
     * @param  list<Attachment>  $attachments
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public Address $from,
        public array $to,
        public string $subject,
        public ?string $text = null,
        public ?string $html = null,
        public array $cc = [],
        public array $bcc = [],
        public array $replyTo = [],
        array $attachments = [],
        public array $headers = [],
        ?\DateTimeImmutable $date = null,
        ?string $messageId = null,
    ) {
        if ($to === [] && $cc === [] && $bcc === []) {
            throw new \InvalidArgumentException('A message needs at least one recipient');
        }

        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new \InvalidArgumentException('A subject must not span lines');
        }

        foreach ($headers as $name => $value) {
            if (\in_array(strtolower($name), self::RESERVED, true)) {
                throw new \InvalidArgumentException("The message owns the {$name} header");
            }

            if (preg_match('/[\r\n\x00]/', $name . $value) === 1) {
                throw new \InvalidArgumentException("The {$name} header must not span lines");
            }
        }

        $this->date = $date ?? new \DateTimeImmutable();
        $this->messageId = $messageId ?? bin2hex(random_bytes(16)) . substr($from->email, (int) strrpos($from->email, '@'));
        $this->root = $this->build($text, $html, $attachments);
    }

    /**
     * Whether sending needs the SMTPUTF8 extension of RFC 6531.
     */
    public function isInternational(): bool
    {
        foreach ([$this->from, ...$this->to, ...$this->cc, ...$this->bcc, ...$this->replyTo] as $address) {
            if ($address->isInternational()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Generator<string>
     */
    public function toIterable(): \Generator
    {
        yield $this->head() . $this->root->head();

        yield from $this->root->body();
    }

    public function __toString(): string
    {
        return implode('', iterator_to_array($this->toIterable(), false));
    }

    private function head(): string
    {
        $fields = [
            'Date' => $this->date->format(\DateTimeInterface::RFC2822),
            'From' => (string) $this->from,
            'To' => $this->list($this->to),
            'Cc' => $this->list($this->cc),
            'Reply-To' => $this->list($this->replyTo),
            'Subject' => $this->subject,
            'Message-ID' => "<{$this->messageId}>",
            'MIME-Version' => '1.0',
            ...$this->headers,
        ];

        $head = '';

        foreach ($fields as $name => $value) {
            if ($value !== '') {
                $head .= Header::line($name, $value);
            }
        }

        return $head;
    }

    /**
     * @param  list<Address>  $addresses
     */
    private function list(array $addresses): string
    {
        return implode(', ', array_map(static fn(Address $address): string => (string) $address, $addresses));
    }

    /**
     * @param  list<Attachment>  $attachments
     */
    private function build(?string $text, ?string $html, array $attachments): Part
    {
        $parts = [];

        if ($text !== null) {
            $parts[] = Part::text('text/plain', $text);
        }

        if ($html !== null) {
            $parts[] = Part::text('text/html', $html);
        }

        $body = match (\count($parts)) {
            0 => Part::text('text/plain', ''),
            1 => $parts[0],
            default => Part::multipart('alternative', ...$parts),
        };

        $inline = array_values(array_filter($attachments, static fn(Attachment $file): bool => $file->cid !== null));
        $files = array_values(array_filter($attachments, static fn(Attachment $file): bool => $file->cid === null));

        if ($inline !== []) {
            $body = Part::multipart('related', $body, ...array_map(Part::attachment(...), $inline));
        }

        if ($files !== []) {
            return Part::multipart('mixed', $body, ...array_map(Part::attachment(...), $files));
        }

        return $body;
    }
}
