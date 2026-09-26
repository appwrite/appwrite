<?php

namespace Utopia\DNS\Adapter\Swoole;

use Swoole\Server;
use Swoole\Server\Port;
use Utopia\DNS\Protocol;

class Udp extends Transport
{
    public function getSockType(): int
    {
        return SWOOLE_SOCK_UDP;
    }

    public function getSettings(): array
    {
        return [];
    }

    public function attach(Server|Port $target, callable $onPacket): void
    {
        // Enforces the 512-byte response limit per RFC 1035
        $target->on('Packet', function ($server, $data, $clientInfo) use ($onPacket): void {
            if (!\is_string($data) || !\is_array($clientInfo)) {
                return;
            }

            $ip = \is_string($clientInfo['address'] ?? null) ? $clientInfo['address'] : '';
            $port = \is_int($clientInfo['port'] ?? null) ? $clientInfo['port'] : 0;

            $response = \call_user_func($onPacket, $data, $ip, $port, Protocol::Udp);

            if ($response !== '' && $server instanceof Server) {
                $server->sendto($ip, $port, $response);
            }
        });
    }
}
