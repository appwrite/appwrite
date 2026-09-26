<?php

declare(strict_types=1);

namespace Utopia\DNS;

use Exception;
use Utopia\Validator\IP;

class Client
{
    public function __construct(
        protected string $server = '127.0.0.1',
        protected int $port = 53,
        protected int $timeout = 5,
        protected bool $useTcp = false,
        /** @var \Socket|null */
        protected ?\Socket $socket = null,
    ) {
        $validator = new IP(IP::ALL); // IPv4 + IPv6
        if (!$validator->isValid($server)) {
            throw new Exception('Server must be an IP address.');
        }

        if ($this->useTcp) {
            return;
        }

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if ($socket === false) {
            throw new Exception('Failed to create socket: ' . socket_strerror(socket_last_error()));
        }

        // Set socket timeout
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $timeout, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $timeout, 'usec' => 0]);

        $this->socket = $socket;
    }

    public function query(Message $message): Message
    {
        if ($this->useTcp) {
            return $this->queryTcp($message);
        }

        if (!$this->socket instanceof \Socket) {
            throw new Exception('UDP socket not initialized.');
        }

        $packet = $message->encode();
        if (socket_sendto($this->socket, $packet, \strlen($packet), 0, $this->server, $this->port) === false) {
            throw new Exception('Failed to send data: ' . socket_strerror(socket_last_error($this->socket)));
        }

        $data = '';
        $from = '';
        $port = 0;

        $result = socket_recvfrom($this->socket, $data, 512, 0, $from, $port);

        if ($result === false) {
            $error = socket_last_error($this->socket);
            $errorMessage = socket_strerror($error);
            throw new Exception("Failed to receive data from $this->server: $errorMessage (Error code: $error)");
        }

        if (empty($data) || !\is_string($data)) {
            throw new Exception("Empty response received from $this->server:$this->port");
        }

        return $this->decodeResponse($message, $data);
    }

    protected function queryTcp(Message $message): Message
    {
        $targetHost = $this->formatTcpHost($this->server);
        $uri = "tcp://{$targetHost}:{$this->port}";

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($uri, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT);

        if ($socket === false) {
            $errCode = \is_int($errno) ? $errno : 0;
            $errMsg = \is_string($errstr) ? $errstr : 'Unknown error';
            throw new Exception("Failed to connect to {$this->server}:{$this->port} over TCP: $errMsg ($errCode)");
        }

        try {
            stream_set_timeout($socket, $this->timeout);

            $packet = $message->encode();
            $frame = pack('n', \strlen($packet)) . $packet;

            $written = fwrite($socket, $frame);

            if ($written === false || $written < \strlen($frame)) {
                throw new Exception('Failed to send full TCP DNS query.');
            }

            $lengthBytes = $this->readBytes($socket, 2);

            if (\strlen($lengthBytes) !== 2) {
                throw new Exception('Failed to read DNS TCP length prefix.');
            }

            $unpacked = unpack('nlen', $lengthBytes);
            $length = (\is_array($unpacked) && isset($unpacked['len']) && \is_int($unpacked['len'])) ? $unpacked['len'] : 0;

            if ($length === 0) {
                throw new Exception('Received empty DNS TCP response.');
            }

            $payload = $this->readBytes($socket, $length);

            if (\strlen($payload) !== $length) {
                throw new Exception('Incomplete DNS TCP response received.');
            }

            return $this->decodeResponse($message, $payload);
        } finally {
            fclose($socket);
        }
    }

    protected function decodeResponse(Message $query, string $payload): Message
    {
        $response = Message::decode($payload);

        if ($response->header->id !== $query->header->id) {
            throw new Exception("Mismatched DNS transaction ID. Expected {$query->header->id}, got {$response->header->id}");
        }

        return $response;
    }

    protected function readBytes(mixed $socket, int $length): string
    {
        if (!\is_resource($socket)) {
            return '';
        }

        $data = '';

        while (\strlen($data) < $length) {
            $remaining = $length - \strlen($data);

            if ($remaining <= 0) {
                break;
            }

            $chunk = fread($socket, max(1, $remaining));

            if ($chunk === false) {
                break;
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($socket);

                if (!empty($meta['timed_out']) || !empty($meta['eof'])) {
                    break;
                }

                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }

    protected function formatTcpHost(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '[' . $host . ']';
        }

        return $host;
    }
}
