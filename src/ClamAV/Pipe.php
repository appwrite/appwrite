<?php

namespace ClamAV;

class Pipe extends ClamAV
{
    const CLAMAV_HOST = '/var/run/clamav/clamd.ctl';

    private $pip;

    /**
     * Pipe constructor.
     *
     * This class can be used to connect to local socket.
     * You need to pass the path to the socket pipe.
     *
     * @param string $pip
     */
    public function __construct(string $pip = self::CLAMAV_HOST)
    {
        $this->pip = $pip;
    }

    protected function getSocket()
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        socket_connect($socket, $this->pip);
        return $socket;
    }
}