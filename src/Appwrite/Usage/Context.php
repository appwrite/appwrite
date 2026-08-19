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
    protected string $country = '';
    protected string $region = '';
    protected string $hostname = '';
    protected string $userAgent = '';
    protected string $ip = '';
    protected string $sdk = '';
    protected string $sdkVersion = '';

    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function setService(string $service): self
    {
        $this->service = $service;
        return $this;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function setResource(string $resource): self
    {
        return $this->setResourceType($resource);
    }

    public function setResourceType(string $resourceType): self
    {
        $this->resourceType = $resourceType;
        return $this;
    }

    public function setResourceId(string $resourceId): self
    {
        $this->resourceId = $resourceId;
        return $this;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function setResourceInternalId(string $resourceInternalId): self
    {
        $this->resourceInternalId = $resourceInternalId;
        return $this;
    }

    public function setResourcePath(string $path): self
    {
        $this->resourcePath = $path;
        return $this;
    }

    public function getResourcePath(): string
    {
        return $this->resourcePath;
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

    public function setCountry(string $country): self
    {
        $this->country = self::normalizeCountry($country);
        return $this;
    }

    public function setRegion(string $region): self
    {
        $this->region = $region;
        return $this;
    }

    public function setHostname(string $origin): self
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

    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function setIp(string $ip): self
    {
        $this->ip = $ip;
        return $this;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function setSdk(string $sdk): self
    {
        $this->sdk = $sdk;
        return $this;
    }

    public function setSdkVersion(string $sdkVersion): self
    {
        $this->sdkVersion = $sdkVersion;
        return $this;
    }

    /**
     * Add a metric with the metadata active at the time it was emitted.
     */
    public function addMetric(string $key, int $value): self
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

    public function fillMissingResource(string $resourceType, string $resourceId, string $resourceInternalId): self
    {
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

    public function reset(): self
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
