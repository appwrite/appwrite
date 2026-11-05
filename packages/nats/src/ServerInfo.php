<?php

declare(strict_types=1);

namespace Utopia\NATS;

final class ServerInfo
{
    public function __construct(
        public readonly string $serverId,
        public readonly string $serverName,
        public readonly string $version,
        public readonly int $proto,
        public readonly string $host,
        public readonly int $port,
        public readonly bool $headersSupported,
        public readonly bool $authRequired,
        public readonly bool $tlsRequired,
        public readonly bool $tlsAvailable,
        public readonly int $maxPayload,
        public readonly array $connectUrls,
        public readonly ?string $nonce,
        public readonly bool $jetstream,
        public readonly ?int $clientId,
        public readonly ?string $clientIp,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            serverId: $data['server_id'] ?? '',
            serverName: $data['server_name'] ?? '',
            version: $data['version'] ?? '',
            proto: $data['proto'] ?? 0,
            host: $data['host'] ?? '',
            port: $data['port'] ?? 0,
            headersSupported: $data['headers'] ?? false,
            authRequired: $data['auth_required'] ?? false,
            tlsRequired: $data['tls_required'] ?? false,
            tlsAvailable: $data['tls_available'] ?? false,
            maxPayload: $data['max_payload'] ?? 1048576,
            connectUrls: $data['connect_urls'] ?? [],
            nonce: $data['nonce'] ?? null,
            jetstream: $data['jetstream'] ?? false,
            clientId: $data['client_id'] ?? null,
            clientIp: $data['client_ip'] ?? null,
        );
    }
}
