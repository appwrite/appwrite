<?php

namespace ClamAV;

class Network extends ClamAV
{
    const CLAMAV_HOST = '127.0.0.1';
    const CLAMAV_PORT = 3310;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * Network constructor
     *
     * You need to pass the host address and the port the the server
     *
     * @param string $host
     * @param int $port
     */
    public function __construct(string $host = self::CLAMAV_HOST, int $port = self::CLAMAV_PORT)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * @return resource
     * @throws \Exception
     */
    protected function getSocket()
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, 0);
        $status = socket_connect($socket, $this->host, $this->port);

        if(!$status) {
            throw new \Exception('Unable to connect to ClamAV server');
        }
        return $socket;
    }
}