<?php

namespace Utopia\Migration\Resources\Integrations;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class ApiKey extends Resource
{
    /**
     * @param string $id
     * @param string $name
     * @param array<string> $scopes
     * @param string $expire
     * @param string $accessedAt
     * @param array<string> $sdks
     * @param string $createdAt
     * @param string $updatedAt
     */
    public function __construct(
        string $id,
        private readonly string $name,
        private readonly array $scopes = [],
        private readonly string $expire = '',
        private readonly string $accessedAt = '',
        private readonly array $sdks = [],
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
            $array['scopes'] ?? [],
            $array['expire'] ?? '',
            $array['accessedAt'] ?? '',
            $array['sdks'] ?? [],
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
            'scopes' => $this->scopes,
            'expire' => $this->expire,
            'accessedAt' => $this->accessedAt,
            'sdks' => $this->sdks,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_API_KEY;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_INTEGRATIONS;
    }

    public function getApiKeyName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getExpire(): string
    {
        return $this->expire;
    }

    public function getAccessedAt(): string
    {
        return $this->accessedAt;
    }

    /**
     * @return array<string>
     */
    public function getSdks(): array
    {
        return $this->sdks;
    }
}
