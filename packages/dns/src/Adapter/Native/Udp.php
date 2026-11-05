<?php

namespace Utopia\DNS\Adapter\Native;

use Exception;
use Socket;
use Utopia\DNS\Protocol;

class Udp extends Transport
{
    protected ?Socket $socket = null;

    public function bind(): void
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$socket instanceof Socket) {
            throw new Exception('Could not create UDP socket.');
        }

        if (socket_bind($socket, $this->host, $this->port) === false) {
            throw new Exception(\sprintf('Could not bind UDP socket to %s:%d.', $this->host, $this->port));
        }

        $this->socket = $socket;
    }

    public function getSockets(): array
    {
        return $this->socket instanceof Socket ? [$this->socket] : [];
    }

    public function onReadable(Socket $socket, callable $onPacket): void
    {
        $buffer = '';
        $ip = '';
        $port = 0;
        $length = socket_recvfrom($socket, $buffer, 1024 * 4, 0, $ip, $port);

        if ($length > 0 && \is_string($buffer) && \is_string($ip) && \is_int($port)) {
            $answer = \call_user_func($onPacket, $buffer, $ip, $port, Protocol::Udp);

            if ($answer !== '') {
                socket_sendto($socket, $answer, \strlen($answer), 0, $ip, $port);
            }
        }
    }
}
