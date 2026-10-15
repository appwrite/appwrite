<?php

namespace Utopia\Migration\Resources\Settings;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Webhook extends Resource
{
    /**
     * @param array<string> $events
     */
    public function __construct(
        string $id,
        private readonly string $name,
        private readonly string $url,
        private readonly array $events = [],
        private readonly bool $security = false,
        private readonly string $httpUser = '',
        private readonly string $httpPass = '',
        private readonly bool $enabled = true,
        string $createdAt = '',
        string $updatedAt = '',
    ) {
        $this->id = $id;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * @param array<string, mixed> $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            $array['name'],
            $array['url'],
            $array['events'] ?? [],
            (bool) ($array['security'] ?? false),
            $array['httpUser'] ?? '',
            $array['httpPass'] ?? '',
            (bool) ($array['enabled'] ?? true),
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
            'name' => $this->name,
            'url' => $this->url,
            'events' => $this->events,
            'security' => $this->security,
            'httpUser' => $this->httpUser,
            'httpPass' => $this->httpPass,
            'enabled' => $this->enabled,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_WEBHOOK;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_INTEGRATIONS;
    }

    public function getWebhookName(): string
    {
        return $this->name;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return array<string>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function getSecurity(): bool
    {
        return $this->security;
    }

    public function getHttpUser(): string
    {
        return $this->httpUser;
    }

    public function getHttpPass(): string
    {
        return $this->httpPass;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
