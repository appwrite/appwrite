<?php

namespace Appwrite\Event\Message;

use Utopia\Config\Config;
use Utopia\Database\Document;

final class Mail extends Base
{
    public function __construct(
        public readonly ?Document $project = null,
        public readonly string $recipient = '',
        public readonly string $name = '',
        public readonly string $subject = '',
        public readonly string $bodyTemplate = '',
        public readonly string $body = '',
        public readonly string $preview = '',
        public readonly array $smtp = [],
        public readonly array $variables = [],
        public readonly array $attachment = [],
        public readonly array $customMailOptions = [],
        public readonly array $events = [],
        public readonly array $platform = [],
    ) {
    }

    public function toArray(): array
    {
        $platform = !empty($this->platform) ? $this->platform : Config::getParam('platform', []);

        return [
            'project' => $this->project?->getArrayCopy(),
            'recipient' => $this->recipient,
            'name' => $this->name,
            'subject' => $this->subject,
            'bodyTemplate' => $this->bodyTemplate,
            'body' => $this->body,
            'preview' => $this->preview,
            'smtp' => $this->smtp,
            'variables' => $this->variables,
            'attachment' => $this->attachment,
            'customMailOptions' => $this->customMailOptions,
            'events' => $this->events,
            'platform' => $platform,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: !empty($data['project']) ? new Document($data['project']) : null,
            recipient: $data['recipient'] ?? '',
            name: $data['name'] ?? '',
            subject: $data['subject'] ?? '',
            bodyTemplate: $data['bodyTemplate'] ?? '',
            body: $data['body'] ?? '',
            preview: $data['preview'] ?? '',
            smtp: $data['smtp'] ?? [],
            variables: $data['variables'] ?? [],
            attachment: $data['attachment'] ?? [],
            customMailOptions: $data['customMailOptions'] ?? [],
            events: $data['events'] ?? [],
            platform: $data['platform'] ?? [],
        );
    }
}
