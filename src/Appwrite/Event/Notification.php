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
    protected array $smtp = [];
    protected array $variables = [];
    protected string $bodyTemplate = '';
    protected array $attachment = [];
    protected array $customMailOptions = [];

    /**
     * @var array<int, array{address: string, channel: string}>
     */
    protected array $recipients = [];

    /**
     * @var array<string>
     */
    protected array $channels = [];

    protected string $template = '';
    protected array $templateParams = [];
    protected string $dedupKey = '';

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

    public function setSmtpHost(string $host): self
    {
        $this->smtp['host'] = $host;
        return $this;
    }

    public function setSmtpPort(int $port): self
    {
        $this->smtp['port'] = $port;
        return $this;
    }

    public function setSmtpUsername(string $username): self
    {
        $this->smtp['username'] = $username;
        return $this;
    }

    public function setSmtpPassword(string $password): self
    {
        $this->smtp['password'] = $password;
        return $this;
    }

    public function setSmtpSecure(string $secure): self
    {
        $this->smtp['secure'] = $secure;
        return $this;
    }

    public function setSmtpSenderEmail(string $senderEmail): self
    {
        $this->smtp['senderEmail'] = $senderEmail;
        return $this;
    }

    public function setSmtpSenderName(string $senderName): self
    {
        $this->smtp['senderName'] = $senderName;
        return $this;
    }

    public function setSmtpReplyToEmail(string $email): self
    {
        $this->smtp['replyToEmail'] = $email;
        return $this;
    }

    public function setSmtpReplyToName(string $name): self
    {
        $this->smtp['replyToName'] = $name;
        return $this;
    }

    public function getSmtpHost(): string
    {
        return $this->smtp['host'] ?? '';
    }

    public function getSmtpPort(): int
    {
        return $this->smtp['port'] ?? 0;
    }

    public function getSmtpUsername(): string
    {
        return $this->smtp['username'] ?? '';
    }

    public function getSmtpPassword(): string
    {
        return $this->smtp['password'] ?? '';
    }

    public function getSmtpSecure(): string
    {
        return $this->smtp['secure'] ?? '';
    }

    public function getSmtpSenderEmail(): string
    {
        return $this->smtp['senderEmail'] ?? '';
    }

    public function getSmtpSenderName(): string
    {
        return $this->smtp['senderName'] ?? '';
    }

    public function getSmtpReplyToEmail(): string
    {
        return $this->smtp['replyToEmail'] ?? '';
    }

    public function getSmtpReplyToName(): string
    {
        return $this->smtp['replyToName'] ?? '';
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

    public function setSenderEmail(string $email): self
    {
        $this->customMailOptions['senderEmail'] = $email;
        return $this;
    }

    public function getSenderEmail(): string
    {
        return $this->customMailOptions['senderEmail'] ?? '';
    }

    public function setSenderName(string $name): self
    {
        $this->customMailOptions['senderName'] = $name;
        return $this;
    }

    public function getSenderName(): string
    {
        return $this->customMailOptions['senderName'] ?? '';
    }

    public function setReplyToEmail(string $email): self
    {
        $this->customMailOptions['replyToEmail'] = $email;
        return $this;
    }

    public function getReplyToEmail(): string
    {
        return $this->customMailOptions['replyToEmail'] ?? '';
    }

    public function setReplyToName(string $name): self
    {
        $this->customMailOptions['replyToName'] = $name;
        return $this;
    }

    public function getReplyToName(): string
    {
        return $this->customMailOptions['replyToName'] ?? '';
    }

    /**
     * @param array<int, array{address: string, channel: string}> $recipients
     */
    public function setRecipients(array $recipients): self
    {
        $this->recipients = $recipients;
        return $this;
    }

    /**
     * @return array<int, array{address: string, channel: string}>
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function addRecipient(string $address, string $channel = NOTIFICATION_CHANNEL_EMAIL): self
    {
        $this->recipients[] = ['address' => $address, 'channel' => $channel];
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

    public function setDedupKey(string $dedupKey): self
    {
        $this->dedupKey = $dedupKey;
        return $this;
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
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
        $this->project = null;
        $this->recipient = '';
        $this->name = '';
        $this->subject = '';
        $this->body = '';
        $this->variables = [];
        $this->bodyTemplate = '';
        $this->attachment = [];
        $this->customMailOptions = [];
        $this->recipients = [];
        $this->channels = [];
        $this->template = '';
        $this->templateParams = [];
        $this->dedupKey = '';
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
                'channel' => NOTIFICATION_CHANNEL_EMAIL,
            ]];
        }

        return [
            'project' => $this->project,
            'recipient' => $this->recipient,
            'recipients' => $recipients,
            'channels' => $this->channels,
            'template' => $this->template,
            'templateParams' => $this->templateParams,
            'dedupKey' => $this->dedupKey,
            'permissions' => $this->permissions,
            'name' => $this->name,
            'subject' => $this->subject,
            'bodyTemplate' => $this->bodyTemplate,
            'body' => $this->body,
            'preview' => $this->preview,
            'smtp' => $this->smtp,
            'variables' => $this->variables,
            'attachment' => $this->attachment,
            'customMailOptions' => $this->customMailOptions,
            'events' => Event::generateEvents($this->getEvent(), $this->getParams()),
            'platform' => $platform,
        ];
    }
}
