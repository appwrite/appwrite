<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Messaging extends Base
{
    public function __construct(
        public readonly string $type,
        public readonly Document $project,
        public readonly ?Document $user = null,
        public readonly ?string $messageId = null,
        public readonly ?Document $message = null,
        public readonly ?array $recipients = null,
        public readonly ?string $providerType = null,
        public readonly ?string $channel = null,
        public readonly ?bool $fallback = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'project' => $this->project->getArrayCopy(),
            'user' => $this->user?->getArrayCopy(),
            'messageId' => $this->messageId,
            'message' => $this->message?->getArrayCopy(),
            'recipients' => $this->recipients,
            'providerType' => $this->providerType,
            'channel' => $this->channel,
            'fallback' => $this->fallback,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            type: $data['type'] ?? '',
            project: new Document($data['project'] ?? []),
            user: !empty($data['user']) ? new Document($data['user']) : null,
            messageId: $data['messageId'] ?? null,
            message: !empty($data['message']) ? new Document($data['message']) : null,
            recipients: $data['recipients'] ?? null,
            providerType: $data['providerType'] ?? null,
            channel: $data['channel'] ?? null,
            fallback: $data['fallback'] ?? null,
        );
    }
}
