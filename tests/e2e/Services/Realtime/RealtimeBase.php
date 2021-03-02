<?php

namespace Tests\E2E\Services\Realtime;

use Ratchet;
use Ratchet\RFC6455\Messaging\MessageInterface;

trait RealtimeBase
{
    private function getWebsocket($channels = []) {
        $query = [
            'project' => $this->getProject()['$id'],
            'channels' => $channels
        ];
        return 'ws://appwrite-traefik/v1/realtime?' . http_build_query($query);
    }

    public function testHandshake()
    {
        /**
         * Test for SUCCESS
         */
        Ratchet\Client\connect($this->getWebsocket(['documents']), [], ['origin' => 'appwrite.test'])->then(function($conn) {
            $conn->on('message', function(MessageInterface $msg) use ($conn) {
                $this->assertEquals('{"documents":0}', $msg->__toString());
                $conn->close();
            });
        }, function ($e) {
            echo "Could not connect: {$e->getMessage()}\n";
        });

        /**
         * Test for FAILURE
         */
        Ratchet\Client\connect($this->getWebsocket(['account']), [], ['origin' => 'appwrite.test'])->then(function($conn) {
            $conn->on('message', function(Message $msg) use ($conn) {
                $this->assertEquals('Missing channels', $msg->__toString());
                $conn->close();
            });
        }, function ($e) {
            echo "Could not connect: {$e->getMessage()}\n";
        });
    }
}