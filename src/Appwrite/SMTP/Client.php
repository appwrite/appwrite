<?php

namespace Appwrite\SMTP;

use Closure;
use Utopia\Messaging\Messages\Email as EmailMessage;

class Client
{
    /**
     * @param Closure(EmailMessage): array<string, mixed> $sender
     */
    public function __construct(private readonly Closure $sender)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function send(EmailMessage $message): array
    {
        return ($this->sender)($message);
    }
}
