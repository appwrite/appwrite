<?php

namespace ClamAV;

/**
 * Class ClamAV
 *
 * An abstract class that `ClamdPipe` and `ClamdNetwork` will inherit.
 *
 * @package ClamAV
 */
abstract class ClamAV {

    const CLAMAV_MAXP = 20000;

    /**
     * @return mixed
     */
    abstract protected function getSocket();

    /* Send command to Clamd */
    private function sendCommand($command)
    {
        $return = null;

        $socket = $this->getSocket();

        socket_send($socket, $command, strlen($command), 0);
        socket_recv($socket, $return, self::CLAMAV_MAXP, 0);
        socket_close($socket);

        return $return;
    }

    /* `ping` command is used to see whether Clamd is alive or not */
    public function ping()
    {
        $return = $this->sendCommand('PING');
        return strcmp($return, 'PONG') ? true : false;
    }

    /* `version` is used to receive the version of Clamd */
    public function version()
    {
        return trim($this->sendCommand('VERSION'));
    }

    /* `reload` Reload Clamd */
    public function reload()
    {
        return $this->sendCommand('RELOAD');
    }

    /* `shutdown` Shutdown Clamd */
    public function shutdown()
    {
        return $this->sendCommand('SHUTDOWN');
    }

    /* `fileScan` is used to scan single file. */
    public function fileScan($file)
    {
        $out = $this->sendCommand('SCAN ' .  $file);

        list($file, $stats) = explode(':', $out);

        $result = trim($stats);

        return ($result === 'OK');
    }

    /* `continueScan` is used to scan multiple files/directories.  */
    public function continueScan($file)
    {
        $return = array();

        foreach(explode("\n", trim($this->sendCommand('CONTSCAN ' .  $file))) as $results ) {
            list($file, $stats) = explode(':', $results);
            array_push($return, array( 'file' => $file, 'stats' => trim($stats) ));
        }

        return $return;
    }

    /* `streamScan` is used to scan a buffer. */
    public function streamScan($buffer)
    {
        $port    = null;
        $socket  = null;
        $command = 'STREAM';
        $return  = null;

        $socket = $this->getSocket();

        socket_send($socket, $command, strlen($command), 0);
        socket_recv($socket, $return, self::CLAMAV_MAXP, 0);

        sscanf($return, 'PORT %d\n', $port);

        $stream = socket_create(AF_INET, SOCK_STREAM, 0);

        socket_connect($stream, self::CLAMAV_MAXP, $port);
        socket_send($stream, $buffer, strlen($buffer), 0);
        socket_close($stream);

        socket_recv($socket, $return, self::CLAMAV_MAXP, 0);

        socket_close($socket);

        return array('stats' => trim(str_replace('stream: ', '', $return)));
    }
}
