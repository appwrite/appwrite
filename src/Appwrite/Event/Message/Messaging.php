<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Messaging extends Base
{
    public function __construct(
        public readonly string $type,
        public readonly Document $project,
        public readonly ?string $messageId = null,
        public readonly ?Document $message = null,
        public readonly ?array $recipients = null,
        public readonly ?string $providerType = null,
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'project' => ProjectContext::fromDocument($this->project)->toArray(),
            'messageId' => $this->messageId,
            'message' => $this->message?->getArrayCopy(),
            'recipients' => $this->recipients,
            'providerType' => $this->providerType,
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        return new self(
            type: $data['type'] ?? '',
            project: new Document($data['project'] ?? []),
            messageId: $data['messageId'] ?? null,
            message: !empty($data['message']) ? new Document($data['message']) : null,
            recipients: $data['recipients'] ?? null,
            providerType: $data['providerType'] ?? null,
        );
    }
}
