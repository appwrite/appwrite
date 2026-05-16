<?php

namespace Appwrite\Event;

use Utopia\Config\Config;
use Utopia\Queue\Publisher;
use Utopia\System\System;

class Notification extends Event
{
    protected string $recipient = '';
    protected string $name = '';
    protected string $subject = '';
    protected string $body = '';
    protected string $preview = '';
    protected array $variables = [];
    protected string $bodyTemplate = '';
    protected array $attachment = [];

    /**
     * Recipients to deliver the notification to.
     *
     * Each entry has an `address` (channel-specific identifier: email,
     * user ID, or webhook URL) and a `channel`. Webhook recipients may
     * additionally carry an optional `signatureKey`; when set, the
     * webhook adapter signs the request body with HMAC-SHA256 and adds
     * the `X-Appwrite-Webhook-Signature` header. Without a key the
     * payload is sent unsigned. Resource fields identify the alert owner.
     *
     * @var array<int, array{address: string, channel: string, signatureKey?: string, resourceType?: string, resourceId?: string, resourceInternalId?: string, parentResourceType?: string, parentResourceId?: string, parentResourceInternalId?: string}>
     */
    protected array $recipients = [];

    /**
     * @var array<string>
     */
    protected array $channels = [];

    protected string $template = '';
    protected array $templateParams = [];
    protected string $deduplicationKey = '';

    /**
     * @var array<string>
     */
    protected array $permissions = [];

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(System::getEnv('_APP_NOTIFICATIONS_QUEUE_NAME', Event::NOTIFICATIONS_QUEUE_NAME))
            ->setClass(System::getEnv('_APP_NOTIFICATIONS_CLASS_NAME', Event::NOTIFICATIONS_CLASS_NAME));
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setRecipient(string $recipient): self
    {
        $this->recipient = $recipient;
        return $this;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setPreview(string $preview): self
    {
        $this->preview = $preview;
        return $this;
    }

    public function getPreview(): string
    {
        return $this->preview;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setBodyTemplate(string $bodyTemplate): self
    {
        $this->bodyTemplate = $bodyTemplate;
        return $this;
    }

    public function getBodyTemplate(): string
    {
        return $this->bodyTemplate;
    }

    public function getVariables(): array
    {
        return $this->variables;
    }

    public function setVariables(array $variables): self
    {
        $this->variables = $variables;
        return $this;
    }

    public function appendVariables(array $variables): self
    {
        $this->variables = \array_merge($this->variables, $variables);
        return $this;
    }

    public function setAttachment(string $content, string $filename, string $encoding = 'base64', string $type = 'plain/text'): self
    {
        $this->attachment = [
            'content' => \base64_encode($content),
            'filename' => $filename,
            'encoding' => $encoding,
            'type' => $type,
        ];
        return $this;
    }

    public function getAttachment(): array
    {
        return $this->attachment;
    }

    public function resetAttachment(): self
    {
        $this->attachment = [];
        return $this;
    }

    /**
     * @param array<int, array{address: string, channel: string, signatureKey?: string, resourceType?: string, resourceId?: string, resourceInternalId?: string, parentResourceType?: string, parentResourceId?: string, parentResourceInternalId?: string}> $recipients
     */
    public function setRecipients(array $recipients): self
    {
        $this->recipients = $recipients;
        return $this;
    }

    /**
     * @return array<int, array{address: string, channel: string, signatureKey?: string, resourceType?: string, resourceId?: string, resourceInternalId?: string, parentResourceType?: string, parentResourceId?: string, parentResourceInternalId?: string}>
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function addRecipient(
        string $address,
        string $channel = NOTIFICATION_TYPE_EMAIL,
        ?string $signatureKey = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $resourceInternalId = null,
        ?string $parentResourceType = null,
        ?string $parentResourceId = null,
        ?string $parentResourceInternalId = null,
    ): self {
        $recipient = ['address' => $address, 'channel' => $channel];
        if ($signatureKey !== null && $signatureKey !== '') {
            $recipient['signatureKey'] = $signatureKey;
        }
        if ($resourceType !== null && $resourceType !== '') {
            $recipient['resourceType'] = $resourceType;
        }
        if ($resourceId !== null && $resourceId !== '') {
            $recipient['resourceId'] = $resourceId;
        }
        if ($resourceInternalId !== null && $resourceInternalId !== '') {
            $recipient['resourceInternalId'] = $resourceInternalId;
        }
        if ($parentResourceType !== null && $parentResourceType !== '') {
            $recipient['parentResourceType'] = $parentResourceType;
        }
        if ($parentResourceId !== null && $parentResourceId !== '') {
            $recipient['parentResourceId'] = $parentResourceId;
        }
        if ($parentResourceInternalId !== null && $parentResourceInternalId !== '') {
            $recipient['parentResourceInternalId'] = $parentResourceInternalId;
        }
        $this->recipients[] = $recipient;
        return $this;
    }

    /**
     * @param array<string> $channels
     */
    public function setChannels(array $channels): self
    {
        $this->channels = $channels;
        return $this;
    }

    /**
     * @return array<string>
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    public function setTemplate(string $template): self
    {
        $this->template = $template;
        return $this;
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function setTemplateParams(array $params): self
    {
        $this->templateParams = $params;
        return $this;
    }

    public function getTemplateParams(): array
    {
        return $this->templateParams;
    }

    public function setDeduplicationKey(string $deduplicationKey): self
    {
        $this->deduplicationKey = $deduplicationKey;
        return $this;
    }

    public function getDeduplicationKey(): string
    {
        return $this->deduplicationKey;
    }

    /**
     * @param array<string> $permissions
     */
    public function setPermissions(array $permissions): self
    {
        $this->permissions = $permissions;
        return $this;
    }

    /**
     * @return array<string>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function reset(): self
    {
        parent::reset();

        $this->project = null;
        $this->context = [];
        $this->platform = [];
        $this->user = null;
        $this->userId = null;
        $this->recipient = '';
        $this->name = '';
        $this->subject = '';
        $this->body = '';
        $this->preview = '';
        $this->variables = [];
        $this->bodyTemplate = '';
        $this->attachment = [];
        $this->recipients = [];
        $this->channels = [];
        $this->template = '';
        $this->templateParams = [];
        $this->deduplicationKey = '';
        $this->permissions = [];
        return $this;
    }

    protected function preparePayload(): array
    {
        $platform = $this->platform;
        if (empty($platform)) {
            $platform = Config::getParam('platform', []);
        }

        $recipients = $this->recipients;
        if (empty($recipients) && !empty($this->recipient)) {
            $recipients = [[
                'address' => $this->recipient,
                'channel' => NOTIFICATION_TYPE_EMAIL,
            ]];
        }

        return [
            'project' => $this->project,
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
            'events' => Event::generateEvents($this->getEvent(), $this->getParams()),
            'platform' => $platform,
        ];
    }
}
