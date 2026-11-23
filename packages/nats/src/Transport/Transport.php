<?php

declare(strict_types=1);

namespace Utopia\NATS\Transport;

interface Transport
{
    public function connect(string $host, int $port, float $timeout): void;

    public function write(string $data): int;

    public function read(int $maxBytes, ?float $timeout = null): string;

    public function readLine(?float $timeout = null): string;

    public function upgradeTls(array $options): void;

    public function isConnected(): bool;

    public function close(): void;
}
