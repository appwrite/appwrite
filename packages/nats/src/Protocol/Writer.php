<?php

declare(strict_types=1);

namespace Utopia\NATS\Protocol;

final class Writer
{
    public function connect(array $options): string
    {
        return 'CONNECT ' . json_encode($options, JSON_THROW_ON_ERROR) . "\r\n";
    }

    public function pub(string $subject, string $payload, ?string $replyTo = null): string
    {
        $size = \strlen($payload);

        if ($replyTo !== null) {
            return "PUB {$subject} {$replyTo} {$size}\r\n{$payload}\r\n";
        }

        return "PUB {$subject} {$size}\r\n{$payload}\r\n";
    }

    public function hpub(string $subject, string $headers, string $payload, ?string $replyTo = null): string
    {
        $headerSize = \strlen($headers);
        $totalSize = $headerSize + \strlen($payload);

        if ($replyTo !== null) {
            return "HPUB {$subject} {$replyTo} {$headerSize} {$totalSize}\r\n{$headers}{$payload}\r\n";
        }

        return "HPUB {$subject} {$headerSize} {$totalSize}\r\n{$headers}{$payload}\r\n";
    }

    public function sub(string $subject, string $sid, ?string $queue = null): string
    {
        if ($queue !== null) {
            return "SUB {$subject} {$queue} {$sid}\r\n";
        }

        return "SUB {$subject} {$sid}\r\n";
    }

    public function unsub(string $sid, ?int $maxMessages = null): string
    {
        if ($maxMessages !== null) {
            return "UNSUB {$sid} {$maxMessages}\r\n";
        }

        return "UNSUB {$sid}\r\n";
    }

    public function ping(): string
    {
        return "PING\r\n";
    }

    public function pong(): string
    {
        return "PONG\r\n";
    }
}
