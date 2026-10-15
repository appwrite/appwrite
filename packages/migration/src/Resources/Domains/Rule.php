<?php

namespace Utopia\Migration\Resources\Domains;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Rule extends Resource
{
    public function __construct(
        string $id,
        private readonly string $domain,
        private readonly string $type,
        private readonly string $trigger,
        private readonly string $redirectUrl = '',
        private readonly int $redirectStatusCode = 0,
        private readonly string $deploymentResourceType = '',
        private readonly string $deploymentResourceId = '',
        private readonly string $deploymentVcsProviderBranch = '',
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
            $array['domain'],
            $array['type'],
            $array['trigger'] ?? 'manual',
            (string) ($array['redirectUrl'] ?? ''),
            (int) ($array['redirectStatusCode'] ?? 0),
            (string) ($array['deploymentResourceType'] ?? ''),
            (string) ($array['deploymentResourceId'] ?? ''),
            (string) ($array['deploymentVcsProviderBranch'] ?? ''),
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
            'domain' => $this->domain,
            'type' => $this->type,
            'trigger' => $this->trigger,
            'redirectUrl' => $this->redirectUrl,
            'redirectStatusCode' => $this->redirectStatusCode,
            'deploymentResourceType' => $this->deploymentResourceType,
            'deploymentResourceId' => $this->deploymentResourceId,
            'deploymentVcsProviderBranch' => $this->deploymentVcsProviderBranch,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_RULE;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_DOMAINS;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function getRedirectUrl(): string
    {
        return $this->redirectUrl;
    }

    public function getRedirectStatusCode(): int
    {
        return $this->redirectStatusCode;
    }

    public function getDeploymentResourceType(): string
    {
        return $this->deploymentResourceType;
    }

    public function getDeploymentResourceId(): string
    {
        return $this->deploymentResourceId;
    }

    public function getDeploymentVcsProviderBranch(): string
    {
        return $this->deploymentVcsProviderBranch;
    }
}
