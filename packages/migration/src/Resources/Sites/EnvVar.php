<?php

namespace Utopia\Migration\Resources\Sites;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class EnvVar extends Resource
{
    public function __construct(
        string $id,
        private readonly Site $site,
        private readonly string $key,
        private readonly string $value,
        private readonly bool $secret = false
    ) {
        $this->id = $id;
    }

    /**
     * @param array<string, mixed> $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return (new self(
            $array['id'],
            Site::fromArray($array['site']),
            $array['key'],
            $array['value'],
            (bool) ($array['secret'] ?? false)
        ))->setDescription(\is_string($array['description'] ?? null) ? $array['description'] : '');
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'description' => $this->description,
            'id' => $this->id,
            'site' => $this->site,
            'key' => $this->key,
            'value' => $this->value,
            'secret' => $this->secret,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_SITE_VARIABLE;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_SITES;
    }

    public function getSite(): Site
    {
        return $this->site;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }
}
