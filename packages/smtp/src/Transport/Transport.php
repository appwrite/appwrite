<?php

declare(strict_types=1);

namespace Utopia\SMTP\Transport;

/**
 * Bytes, and nothing else. Line assembly and the reply grammar live in the
 * client, in one place, so a scripted double is all a unit test needs.
 */
interface Transport
{
    /**
     * @param  bool  $tls  Wrap the connection from the first byte, for implicit TLS.
     */
    public function connect(float $timeout, bool $tls): void;

    /**
     * At least one byte and at most $length. Throws rather than returning empty
     * at the end of the stream.
     */
    public function read(int $length, float $timeout): string;

    public function write(string $data, float $timeout): void;

    /**
     * Upgrade an open plaintext connection, for STARTTLS.
     */
    public function startTls(float $timeout): void;

    public function isTls(): bool;

    public function close(): void;
}
