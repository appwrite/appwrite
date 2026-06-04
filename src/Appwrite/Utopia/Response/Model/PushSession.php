<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class PushSession extends Model
{
    public function __construct()
    {
        $this
            ->addRule('endpoint', [
                'type' => self::TYPE_STRING,
                'description' => 'MQTT broker host[:port] the device should connect to.',
                'default' => '',
                'example' => 'push.example.com:8883',
            ])
            ->addRule('tls', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the broker terminates TLS. Devices SHOULD always connect over TLS in production.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('topic', [
                'type' => self::TYPE_STRING,
                'description' => 'MQTT topic the device must subscribe to. Includes the device ID as a suffix.',
                'default' => '',
                'example' => 'appwrite/push/device-abc',
            ])
            ->addRule('clientId', [
                'type' => self::TYPE_STRING,
                'description' => 'MQTT clientId the device must use in CONNECT. Equal to the device ID.',
                'default' => '',
                'example' => 'device-abc',
            ])
            ->addRule('token', [
                'type' => self::TYPE_STRING,
                'description' => 'Short-lived JWT to pass as the MQTT CONNECT password.',
                'default' => '',
                'example' => 'eyJhbGciOiJIUzI1NiJ9...',
            ])
            ->addRule('keepAlive', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Keep-alive interval in seconds the device should use. Tuned to minimise radio wake-ups while staying inside the broker timeout.',
                'default' => 1800,
                'example' => 1800,
            ])
            ->addRule('expiresAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'When the issued token expires. The device must call this endpoint again before this time to reconnect.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ]);
    }

    public function getName(): string
    {
        return 'PushSession';
    }

    public function getType(): string
    {
        return Response::MODEL_PUSH_SESSION;
    }
}
