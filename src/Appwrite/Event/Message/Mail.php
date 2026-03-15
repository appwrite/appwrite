<?php

namespace Appwrite\Event\Message;

use Utopia\Config\Config;
use Utopia\Database\Document;

final readonly class Mail extends Base
{
    /**
     * @param array<string, mixed> $smtp
     * @param array<string, mixed> $variables
     * @param array<mixed> $attachment
     * @param array<string, mixed> $customMailOptions
     * @param array<string, mixed> $platform
     */
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        /** @var array<string, mixed> $project */
        $project = is_array($data['project'] ?? null) ? $data['project'] : [];
        /** @var array<string, mixed> $smtp */
        $smtp = is_array($data['smtp'] ?? null) ? $data['smtp'] : [];
        /** @var array<string, mixed> $variables */
        $variables = is_array($data['variables'] ?? null) ? $data['variables'] : [];
        /** @var array<mixed> $attachment */
        $attachment = is_array($data['attachment'] ?? null) ? $data['attachment'] : [];
        /** @var array<string, mixed> $customMailOptions */
        $customMailOptions = is_array($data['customMailOptions'] ?? null) ? $data['customMailOptions'] : [];
        /** @var array<string, mixed> $platform */
        $platform = is_array($data['platform'] ?? null) ? $data['platform'] : [];

        return new self(
            project: !empty($project) ? new Document($project) : null,
            recipient: is_string($data['recipient'] ?? null) ? $data['recipient'] : '',
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            subject: is_string($data['subject'] ?? null) ? $data['subject'] : '',
            body: is_string($data['body'] ?? null) ? $data['body'] : '',
            preview: is_string($data['preview'] ?? null) ? $data['preview'] : '',
            smtp: $smtp,
            variables: $variables,
            bodyTemplate: is_string($data['bodyTemplate'] ?? null) ? $data['bodyTemplate'] : '',
            attachment: $attachment,
            customMailOptions: $customMailOptions,
            platform: $platform,
        );
    }
}
