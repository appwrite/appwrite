<?php

namespace Utopia\Migration\Resources\Settings;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

/**
 * Singleton resource representing the project's exposed API protocols
 * (REST, GraphQL, WebSocket). One per project; destination flips each
 * via Project::updateProtocol().
 */
class Protocols extends Resource
{
    public function __construct(
        string $id,
        private readonly bool $rest = true,
        private readonly bool $graphql = true,
        private readonly bool $websocket = true,
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
            (bool) ($array['rest'] ?? true),
            (bool) ($array['graphql'] ?? true),
            (bool) ($array['websocket'] ?? true),
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
            'rest' => $this->rest,
            'graphql' => $this->graphql,
            'websocket' => $this->websocket,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_PROJECT_PROTOCOLS;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_PROJECTS;
    }

    public function getRest(): bool
    {
        return $this->rest;
    }

    public function getGraphql(): bool
    {
        return $this->graphql;
    }

    public function getWebsocket(): bool
    {
        return $this->websocket;
    }
}
