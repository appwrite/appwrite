<?php

namespace Appwrite\Usage;

use Utopia\Database\Document;

class Context
{
    protected array $metrics = [];
    protected array $reduce = [];
    protected string $path = '';
    protected string $method = '';
    protected int $status = 0;
    protected string $service = '';
    protected string $resourceType = '';
    protected string $resourceId = '';
    protected string $resourceInternalId = '';
    protected string $resourcePath = '';
    protected string $teamId = '';
    protected string $teamInternalId = '';
    protected string $country = '';
    protected string $region = '';
    protected string $hostname = '';
    protected string $userAgent = '';
    protected string $ip = '';
    protected string $sdk = '';
    protected string $sdkVersion = '';

    public function setPath(string $path): static
    {
        $this->path = $path;
        return $this;
    }

    public function setMethod(string $method): static
    {
        $this->method = $method;
        return $this;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function setService(string $service): static
    {
        $this->service = $service;
        return $this;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function setResource(string $resource): static
    {
        return $this->setResourceType($resource);
    }

    public function setResourceType(string $resourceType): static
    {
        $this->resourceType = $resourceType;
        return $this;
    }

    public function setResourceId(string $resourceId): static
    {
        $this->resourceId = $resourceId;
        return $this;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function setResourceInternalId(string $resourceInternalId): static
    {
        $this->resourceInternalId = $resourceInternalId;
        return $this;
    }

    public function setResourcePath(string $path): static
    {
        $this->resourcePath = $path;
        return $this;
    }

    public function getResourcePath(): string
    {
        return $this->resourcePath;
    }

    public function setTeamId(string $teamId): static
    {
        $this->teamId = $teamId;
        return $this;
    }

    public function setTeamInternalId(string $teamInternalId): static
    {
        $this->teamInternalId = $teamInternalId;
        return $this;
    }

    /**
     * Canonical form of the `country` column. ClickHouse compares strings
     * case-sensitively, so readers must fold filter values the same way this
     * folds them on the way in or an uppercase filter matches nothing.
     */
    public static function normalizeCountry(string $country): string
    {
        return strtolower($country);
    }

    public function setCountry(string $country): static
    {
        $this->country = self::normalizeCountry($country);
        return $this;
    }

    public function setRegion(string $region): static
    {
        $this->region = $region;
        return $this;
    }

    public function setHostname(string $origin): static
    {
        if ($origin === '') {
            $this->hostname = '';
            return $this;
        }

        $host = $origin;
        if (str_contains($host, '://')) {
            $parsed = parse_url($host);
            $host = is_array($parsed) && isset($parsed['host']) ? $parsed['host'] : '';
        } else {
            $host = explode('/', $host, 2)[0];
            $host = explode('?', $host, 2)[0];
        }

        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        $this->hostname = strtolower($host);
        return $this;
    }

    public function setUserAgent(string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function setIp(string $ip): static
    {
        $this->ip = $ip;
        return $this;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function setSdk(string $sdk): static
    {
        $this->sdk = $sdk;
        return $this;
    }

    public function setSdkVersion(string $sdkVersion): static
    {
        $this->sdkVersion = $sdkVersion;
        return $this;
    }

    /**
     * Add a metric with the metadata active at the time it was emitted.
     */
    public function addMetric(string $key, int $value): static
    {
        $this->metrics[] = [
            'key' => $key,
            'value' => $value,
            'path' => $this->path,
            'method' => $this->method,
            'status' => $this->status,
            'service' => $this->service,
            'resourceType' => $this->resourceType,
            'resourceId' => $this->resourceId,
            'resourceInternalId' => $this->resourceInternalId,
            'resourcePath' => $this->resourcePath,
            'teamId' => $this->teamId,
            'teamInternalId' => $this->teamInternalId,
            'country' => $this->country,
            'region' => $this->region,
            'hostname' => $this->hostname,
            'userAgent' => $this->userAgent,
            'ip' => $this->ip,
            'sdk' => $this->sdk,
            'sdkVersion' => $this->sdkVersion,
        ];

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMetrics(): array
    {
        return array_map(function (array $metric): array {
            if ((int) ($metric['status'] ?? 0) === 0) {
                $metric['status'] = $this->status;
            }
            if (($metric['resourcePath'] ?? '') === '') {
                $metric['resourcePath'] = $this->resourcePath;
            }
            return $metric;
        }, $this->metrics);
    }

    /** @return array<Document> */
    public function getReduce(): array
    {
        return $this->reduce;
    }

    public function isEmpty(): bool
    {
        return empty($this->metrics) && empty($this->reduce);
    }

    public function fillMissingResource(string $resourceType, string $resourceId, string $resourceInternalId): static
    {
        $this->setResourceType($resourceType);
        $this->setResourceId($resourceId);
        $this->setResourceInternalId($resourceInternalId);

        foreach ($this->metrics as $index => $metric) {
            if (($metric['resourceType'] ?? '') === '') {
                $this->metrics[$index]['resourceType'] = $resourceType;
            }
            if (($metric['resourceId'] ?? '') === '') {
                $this->metrics[$index]['resourceId'] = $resourceId;
            }
            if (($metric['resourceInternalId'] ?? '') === '') {
                $this->metrics[$index]['resourceInternalId'] = $resourceInternalId;
            }
        }

        return $this;
    }

    public function reset(): static
    {
        $this->metrics = [];
        $this->reduce = [];
        $this->path = '';
        $this->method = '';
        $this->status = 0;
        $this->service = '';
        $this->resourceType = '';
        $this->resourceId = '';
        $this->resourceInternalId = '';
        $this->resourcePath = '';
        $this->teamId = '';
        $this->teamInternalId = '';
        $this->country = '';
        $this->region = '';
        $this->hostname = '';
        $this->userAgent = '';
        $this->ip = '';
        $this->sdk = '';
        $this->sdkVersion = '';

        return $this;
    }
}
