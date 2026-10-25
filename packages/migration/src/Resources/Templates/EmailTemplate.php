<?php

namespace Utopia\Migration\Resources\Templates;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

/**
 * Custom email template — one row per (templateId, locale) pair. The resource
 * `id` follows the storage key format `email.{templateId}-{locale}` so
 * destination read-then-merge can address the slot directly.
 */
class EmailTemplate extends Resource
{
    public function __construct(
        string $id,
        private readonly string $templateId,
        private readonly string $locale,
        private readonly string $subject,
        private readonly string $body,
        private readonly string $senderName = '',
        private readonly string $senderEmail = '',
        private readonly string $replyToEmail = '',
        private readonly string $replyToName = '',
        string $createdAt = '',
        string $updatedAt = '',
    ) {
        $this->id = $id;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * @param array<string, mixed> $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            (string) $array['templateId'],
            (string) $array['locale'],
            (string) ($array['subject'] ?? ''),
            (string) ($array['message'] ?? ''),
            (string) ($array['senderName'] ?? ''),
            (string) ($array['senderEmail'] ?? ''),
            (string) ($array['replyToEmail'] ?? ''),
            (string) ($array['replyToName'] ?? ''),
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'templateId' => $this->templateId,
            'locale' => $this->locale,
            'subject' => $this->subject,
            'message' => $this->body,
            'senderName' => $this->senderName,
            'senderEmail' => $this->senderEmail,
            'replyToEmail' => $this->replyToEmail,
            'replyToName' => $this->replyToName,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_PROJECT_EMAIL_TEMPLATE;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_PROJECTS;
    }

    public function getTemplateId(): string
    {
        return $this->templateId;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getSenderName(): string
    {
        return $this->senderName;
    }

    public function getSenderEmail(): string
    {
        return $this->senderEmail;
    }

    public function getReplyToEmail(): string
    {
        return $this->replyToEmail;
    }

    public function getReplyToName(): string
    {
        return $this->replyToName;
    }
}
