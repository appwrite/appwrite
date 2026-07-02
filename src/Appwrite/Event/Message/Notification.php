<?php

namespace Appwrite\Event\Message;

use Appwrite\Event\Event;
use Utopia\Config\Config;
use Utopia\Database\Document;

final class Notification extends Base
{
    /**
     * @param array<int, array{address: string, channel: string, signatureKey?: string, resourceType?: string, resourceId?: string, resourceInternalId?: string, parentResourceType?: string, parentResourceId?: string, parentResourceInternalId?: string}> $recipients
     * @param array<string> $channels
     * @param array<string, mixed> $templateParams
     * @param array<string> $permissions
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $attachment
     * @param array<string> $events
     * @param array<string, mixed> $params
     * @param array<string, mixed> $platform
     */
    public function __construct(
        public readonly ?Document $project = null,
        public readonly string $recipient = '',
        public readonly array $recipients = [],
        public readonly array $channels = [],
        public readonly string $template = '',
        public readonly array $templateParams = [],
        public readonly string $deduplicationKey = '',
        public readonly array $permissions = [],
        public readonly string $name = '',
        public readonly string $subject = '',
        public readonly string $bodyTemplate = '',
        public readonly string $body = '',
        public readonly string $preview = '',
        public readonly array $variables = [],
        public readonly array $attachment = [],
        public readonly array $events = [],
        public readonly string $event = '',
        public readonly array $params = [],
        public readonly array $platform = [],
    ) {
    }

    public function toArray(): array
    {
        $recipients = $this->recipients;
        if (empty($recipients) && $this->recipient !== '') {
            $recipients = [[
                'address' => $this->recipient,
                'channel' => NOTIFICATION_TYPE_EMAIL,
            ]];
        }

        $platform = !empty($this->platform) ? $this->platform : Config::getParam('platform', []);

        return [
            'project' => $this->project === null ? null : [
                '$id' => $this->project->getId(),
                '$sequence' => $this->project->getSequence(),
                'database' => $this->project->getAttribute('database'),
            ],
            'recipient' => $this->recipient,
            'recipients' => $recipients,
            'channels' => $this->channels,
            'template' => $this->template,
            'templateParams' => $this->templateParams,
            'deduplicationKey' => $this->deduplicationKey,
            'permissions' => $this->permissions,
            'name' => $this->name,
            'subject' => $this->subject,
            'bodyTemplate' => $this->bodyTemplate,
            'body' => $this->body,
            'preview' => $this->preview,
            'variables' => $this->variables,
            'attachment' => $this->attachment,
            'events' => !empty($this->events) ? $this->events : $this->generateEvents(),
            'platform' => $platform,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: !empty($data['project']) ? new Document($data['project']) : null,
            recipient: $data['recipient'] ?? '',
            recipients: $data['recipients'] ?? [],
            channels: $data['channels'] ?? [],
            template: $data['template'] ?? '',
            templateParams: $data['templateParams'] ?? [],
            deduplicationKey: $data['deduplicationKey'] ?? '',
            permissions: $data['permissions'] ?? [],
            name: $data['name'] ?? '',
            subject: $data['subject'] ?? '',
            bodyTemplate: $data['bodyTemplate'] ?? '',
            body: $data['body'] ?? '',
            preview: $data['preview'] ?? '',
            variables: $data['variables'] ?? [],
            attachment: $data['attachment'] ?? [],
            events: $data['events'] ?? [],
            platform: $data['platform'] ?? [],
        );
    }

    /**
     * @return array<string>
     */
    private function generateEvents(): array
    {
        if ($this->event === '') {
            return [];
        }

        return Event::generateEvents($this->event, $this->params);
    }
}
