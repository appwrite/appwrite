<?php

declare(strict_types=1);

namespace Appwrite\Smtp\Mime;

final readonly class Message
{
    /**
     * @param  array<string, list<string>>  $headers
     * @param  list<Attachment>  $attachments
     */
    public function __construct(
        public array $headers,
        public string $subject,
        public string $from,
        public string $to,
        public string $cc,
        public string $replyTo,
        public string $messageId,
        public string $date,
        public string $text,
        public string $html,
        public array $attachments,
    ) {
    }
}
