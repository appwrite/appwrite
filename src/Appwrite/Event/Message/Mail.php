<?php

namespace Appwrite\Event\Message;

use Utopia\Config\Config;
use Utopia\Database\Document;

readonly class Mail extends Base
{
    public function __construct(
        public ?Document $project = null,
        public string $recipient = '',
        public string $name = '',
        public string $subject = '',
        public string $body = '',
        public string $preview = '',
        public array $smtp = [],
        public array $variables = [],
        public string $bodyTemplate = '',
        public array $attachment = [],
        public array $customMailOptions = [],
        public array $platform = [],
    ) {
    }

    public function toArray(): array
    {
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
            'platform' => empty($this->platform) ? Config::getParam('platform', []) : $this->platform,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            project: !empty($data['project']) ? new Document($data['project']) : null,
            recipient: $data['recipient'] ?? '',
            name: $data['name'] ?? '',
            subject: $data['subject'] ?? '',
            body: $data['body'] ?? '',
            preview: $data['preview'] ?? '',
            smtp: $data['smtp'] ?? [],
            variables: $data['variables'] ?? [],
            bodyTemplate: $data['bodyTemplate'] ?? '',
            attachment: $data['attachment'] ?? [],
            customMailOptions: $data['customMailOptions'] ?? [],
            platform: $data['platform'] ?? [],
        );
    }
}
