<?php

namespace Utopia\Messaging\Adapter\Email;

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
            static fn(array $recipient): Address => new Address($recipient['email'], $recipient['name'] ?? ''),
            $recipients,
        ));
    }

    /**
     * @return list<Attachment>
     */
    public static function attachments(EmailMessage $email): array
    {
        return array_values(array_map(
            static fn(EmailAttachment $attachment): Attachment => $attachment->getContent() === null
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
     * The reading a client without markup gets. Stripping tags leaves the
     * contents of a style block behind, so those go first.
     */
    private static function text(EmailMessage $email): string
    {
        if (! $email->isHtml()) {
            return $email->getContent();
        }

        return trim(strip_tags(
            preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $email->getContent()) ?? '',
        ));
    }
}
