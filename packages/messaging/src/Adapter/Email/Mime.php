<?php

namespace Utopia\Messaging\Adapter\Email;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Messages\Email\Attachment as EmailAttachment;
use Utopia\SMTP\Address;
use Utopia\SMTP\Attachment;
use Utopia\SMTP\Message as SmtpMessage;

/**
 * One way to turn a message of ours into a message on the wire.
 *
 * Two adapters need it and want different envelopes: the SMTP one hands over
 * every recipient at once, while SES builds a copy for each. What goes inside
 * is the same either way, so it is written down once.
 */
final class Mime
{
    /**
     * @param  list<array<string, string>>  $to
     * @param  list<array<string, string>>  $cc
     * @param  list<array<string, string>>  $bcc  Carried in the envelope; never written to a header.
     * @param  array<string, string>  $headers
     */
    public static function message(
        EmailMessage $email,
        array $to,
        array $cc = [],
        array $bcc = [],
        array $headers = [],
    ): SmtpMessage {
        return new SmtpMessage(
            from: new Address($email->getFromEmail(), $email->getFromName()),
            to: self::addresses($to),
            subject: $email->getSubject(),
            text: self::text($email),
            html: $email->isHtml() ? $email->getContent() : null,
            cc: self::addresses($cc),
            bcc: self::addresses($bcc),
            replyTo: \in_array($email->getReplyToEmail(), ['', '0'], true)
                ? []
                : [new Address($email->getReplyToEmail(), $email->getReplyToName())],
            attachments: self::attachments($email),
            // The adapter's own headers, such as X-Mailer, win over the message's in any case.
            headers: [
                ...array_diff_ukey($email->getHeaders(), $headers, strcasecmp(...)),
                ...$headers,
            ],
        );
    }

    /**
     * @param  array<array<string, string>>  $recipients
     * @return list<Address>
     */
    public static function addresses(array $recipients): array
    {
        return array_values(array_map(
            static fn (array $recipient): Address => new Address($recipient['email'], $recipient['name'] ?? ''),
            $recipients,
        ));
    }

    /**
     * @return list<Attachment>
     */
    public static function attachments(EmailMessage $email): array
    {
        return array_values(array_map(
            static fn (EmailAttachment $attachment): Attachment => $attachment->getContent() === null
                // Read while the message is written, so a large file is never
                // held in memory twice.
                ? Attachment::fromPath($attachment->getPath(), $attachment->getName(), $attachment->getType())
                : Attachment::fromString($attachment->getContent(), $attachment->getName(), $attachment->getType()),
            $email->getAttachments() ?? [],
        ));
    }

    /**
     * What the attachments weigh, before any of them is encoded.
     */
    public static function size(EmailMessage $email): int
    {
        $size = 0;

        foreach ($email->getAttachments() ?? [] as $attachment) {
            if ($attachment->getContent() !== null) {
                $size += \strlen($attachment->getContent());

                continue;
            }

            $bytes = filesize($attachment->getPath());

            if ($bytes === false) {
                throw new \Exception('Failed to read attachment file: ' . $attachment->getPath());
            }

            $size += $bytes;
        }

        return $size;
    }

    /**
     * The reading a client without markup gets: the visible text with its
     * entities decoded, a line per block, and each link's address beside it
     * so a button still leads somewhere.
     */
    private static function text(EmailMessage $email): string
    {
        if (! $email->isHtml()) {
            return $email->getContent();
        }

        $document = HTMLDocument::createFromString($email->getContent(), LIBXML_NOERROR);

        $text = '';
        foreach (explode("\n", self::render($document->body)) as $line) {
            $line = trim($line, ' ');

            // Blocks end a line; a blank one between them keeps paragraphs apart.
            if ($line !== '' || ! str_ends_with($text, "\n\n")) {
                $text .= $line . "\n";
            }
        }

        // Preformatted lines are joined with a carriage return so the trim above leaves them alone.
        return str_replace("\r", "\n", trim($text));
    }

    private static function render(Node $node, bool $preformatted = false): string
    {
        if ($node instanceof Text) {
            if ($preformatted) {
                return str_replace("\n", "\r", $node->data);
            }

            // Preview padding is a run of invisible characters and no-break spaces
            // with nothing else in it. Elsewhere those characters shape the text.
            if (str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}", ' ', "\t", "\n", "\r", "\f"], '', $node->data) === '') {
                return ' ';
            }

            // Source whitespace means nothing in HTML; collapse it the way a browser would.
            $data = str_replace(["\t", "\n", "\r", "\f"], ' ', $node->data);
            $words = implode(' ', array_filter(explode(' ', $data), static fn (string $word): bool => $word !== ''));

            return (str_starts_with($data, ' ') ? ' ' : '') . $words . (str_ends_with($data, ' ') ? ' ' : '');
        }

        $name = $node instanceof Element ? $node->localName : '';

        if (\in_array($name, ['style', 'script', 'title', 'template'], true)) {
            return '';
        }

        if ($name === 'br') {
            return "\n";
        }

        $preformatted = $preformatted || \in_array($name, ['pre', 'textarea'], true);

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::render($child, $preformatted);
        }

        if ($name === 'a') {
            $href = trim($node->getAttribute('href') ?? '');
            $label = trim($text);

            if ($href !== '' && ! str_starts_with($href, '#') && ! str_starts_with(strtolower($href), 'javascript:') && $href !== $label) {
                $text = $label === '' ? $href : $label . ': ' . $href;
            }
        }

        if (\in_array($name, ['p', 'div', 'tr', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'ul', 'ol', 'blockquote', 'section', 'header', 'footer', 'hr', 'pre'], true)) {
            return "\n\n" . $text . "\n\n";
        }

        return $text;
    }
}
