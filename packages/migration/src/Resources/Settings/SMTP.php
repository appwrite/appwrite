<?php

namespace Utopia\Migration\Resources\Settings;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

/**
 * Singleton resource representing the project's custom SMTP configuration.
 * Password is not migrated — the source API never exposes it.
 */
class SMTP extends Resource
{
    public function __construct(
        string $id,
        private readonly bool $enabled = false,
        private readonly string $senderName = '',
        private readonly string $senderEmail = '',
        private readonly string $replyToName = '',
        private readonly string $replyToEmail = '',
        private readonly string $host = '',
        private readonly int $port = 0,
        private readonly string $username = '',
        private readonly string $secure = '',
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
            (bool) ($array['enabled'] ?? false),
            (string) ($array['senderName'] ?? ''),
            (string) ($array['senderEmail'] ?? ''),
            (string) ($array['replyToName'] ?? ''),
            (string) ($array['replyToEmail'] ?? ''),
            (string) ($array['host'] ?? ''),
            (int) ($array['port'] ?? 0),
            (string) ($array['username'] ?? ''),
            (string) ($array['secure'] ?? ''),
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
            'enabled' => $this->enabled,
            'senderName' => $this->senderName,
            'senderEmail' => $this->senderEmail,
            'replyToName' => $this->replyToName,
            'replyToEmail' => $this->replyToEmail,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'secure' => $this->secure,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_SMTP;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_INTEGRATIONS;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSenderName(): string
    {
        return $this->senderName;
    }

    public function getSenderEmail(): string
    {
        return $this->senderEmail;
    }

    public function getReplyToName(): string
    {
        return $this->replyToName;
    }

    public function getReplyToEmail(): string
    {
        return $this->replyToEmail;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getSecure(): string
    {
        return $this->secure;
    }
}
