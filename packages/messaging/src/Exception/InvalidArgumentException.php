<?php

declare(strict_types=1);

namespace Utopia\Messaging\Exception;

/**
 * Input no provider can deliver, refused when the message is built or when the
 * provider rejects it. `$type` names the reason and `$value` the offending input.
 */
class InvalidArgumentException extends \InvalidArgumentException
{
    public const string MESSAGE_TYPE = 'message_type';

    public const string MESSAGE_EMPTY = 'message_empty';

    public const string TOO_MANY_RECIPIENTS = 'too_many_recipients';

    public const string RECIPIENT_EMPTY = 'recipient_empty';

    public const string RECIPIENT_MALFORMED = 'recipient_malformed';

    public const string RECIPIENT_DOMAIN_INVALID = 'recipient_domain_invalid';

    public const string PROVIDER_REJECTED = 'provider_rejected';

    public const string NAME_MALFORMED = 'name_malformed';

    public const string SENDER_MALFORMED = 'sender_malformed';

    public const string HEADER_MALFORMED = 'header_malformed';

    public function __construct(
        public readonly string $type,
        string $message,
        public readonly ?string $value = null,
    ) {
        parent::__construct($message);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }
}
