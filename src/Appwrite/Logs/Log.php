<?php

namespace Appwrite\Logs;

final readonly class Log
{
    public function __construct(
        // Meta
        public Resource $resource,
        public string   $resourceId,
        public float    $timestamp,
        public float    $durationSeconds,

        // Request
        public Method $requestMethod,
        public string $requestScheme,
        public string $requestHost,
        public string $requestPath,
        public string $requestQuery,
        public int    $requestSizeBytes,

        // Response
        public int $responseStatusCode,
        public int $responseSizeBytes,
    ) {
    }

    public function toArray(): array
    {
        return [
            'resource' => $this->resource->value,
            'resourceId' => $this->resourceId,
            'timestamp' => $this->timestamp,
            'durationSeconds' => $this->durationSeconds,
            'requestMethod' => $this->requestMethod->value,
            'requestScheme' => $this->requestScheme,
            'requestHost' => $this->requestHost,
            'requestPath' => $this->requestPath,
            'requestQuery' => $this->requestQuery,
            'requestSizeBytes' => $this->requestSizeBytes,
            'responseStatusCode' => $this->responseStatusCode,
            'responseSizeBytes' => $this->responseSizeBytes,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            resource: Resource::from($data['resource']),
            resourceId: $data['resourceId'],
            timestamp: (float) $data['timestamp'],
            durationSeconds: (float) $data['durationSeconds'],
            requestMethod: Method::tryFrom($data['requestMethod']) ?? Method::Other,
            requestScheme: $data['requestScheme'] ?? '',
            requestHost: $data['requestHost'] ?? '',
            requestPath: $data['requestPath'] ?? '',
            requestQuery: $data['requestQuery'] ?? '',
            requestSizeBytes: (int) ($data['requestSizeBytes'] ?? 0),
            responseStatusCode: (int) ($data['responseStatusCode'] ?? 0),
            responseSizeBytes: (int) ($data['responseSizeBytes'] ?? 0),
        );
    }
}
