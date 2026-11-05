<?php

namespace Utopia\Messaging\Adapter;

use Utopia\Messaging\Adapter;
use Utopia\Messaging\Messages\Push as PushMessage;

abstract class Push extends Adapter
{
    protected const TYPE = 'push';
    protected const MESSAGE_TYPE = PushMessage::class;
    protected const EXPIRED_MESSAGE = 'Expired device token';

    public function getType(): string
    {
        return static::TYPE;
    }

    public function getMessageType(): string
    {
        return static::MESSAGE_TYPE;
    }

    protected function getExpiredErrorMessage(): string
    {
        return static::EXPIRED_MESSAGE;
    }

    /**
     * Send a push message.
     *
     * @return array{deliveredTo: int, type: string, results: array<array<string, mixed>>}
     *
     * @throws \Exception
     */
    abstract protected function process(PushMessage $message): array;
}
