<?php

namespace Utopia\Messaging\Adapter;

use Utopia\Messaging\Adapter;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Messaging\Messages\SMS as SMSMessage;

abstract class SMS extends Adapter
{
    protected const TYPE = 'sms';
    protected const MESSAGE_TYPE = SMSMessage::class;

    public function getType(): string
    {
        return static::TYPE;
    }

    public function getMessageType(): string
    {
        return static::MESSAGE_TYPE;
    }

    /**
     * Label each attempt with the recipient's country calling code (`91`, `1`),
     * not an ISO region: `1` and `7` span several countries and the number alone
     * cannot tell them apart. Numbers that match no known code count as `unknown`.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function recipientAttributes(?string $recipient): array
    {
        return [
            'country_code' => ($recipient === null ? null : CallingCode::fromPhoneNumber($recipient)) ?? 'unknown',
        ];
    }

    /**
     * Send an SMS message.
     *
     * @param  SMSMessage  $message Message to send.
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     *
     * @throws \Exception If the message fails.
     */
    abstract protected function process(SMSMessage $message): array;
}
