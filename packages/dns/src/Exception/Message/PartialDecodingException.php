<?php

declare(strict_types=1);

namespace Utopia\DNS\Exception\Message;

use Utopia\DNS\Message;
use Utopia\DNS\Message\Header;

/**
 * Exception thrown when a DNS message header is decoded, but the message is not fully decoded.
 */
final class PartialDecodingException extends DecodingException
{
    public function __construct(
        private readonly Header $header,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, Message::RCODE_FORMERR, $previous);
    }

    public function getHeader(): Header
    {
        return $this->header;
    }
}
