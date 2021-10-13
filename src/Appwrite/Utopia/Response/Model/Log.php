<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Log extends Model
{
    public function __construct()
    {
        $this
            ->addRule('event', [
                'type' => self::TYPE_STRING,
                'description' => 'Event name.',
                'default' => '',
                'example' => 'account.sessions.create',
            ])
            ->addRule('ip', [
                'type' => self::TYPE_STRING,
                'description' => 'IP session in use when the session was created.',
                'default' => '',
                'example' => '127.0.0.1',
            ])
            ->addRule('time', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Log creation time in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('osCode', [
                'type' => self::TYPE_STRING,
                'description' => 'Operating system code name. View list of [available options](https://github.com/appwrite/appwrite/blob/master/docs/lists/os.json).',
                'default' => '',
                'example' => 'Mac',
            ])
            ->addRule('osName', [
                'type' => self::TYPE_STRING,
                'description' => 'Operating system name.',
                'default' => '',
                'example' => 'Mac',
            ])
            ->addRule('osVersion', [
                'type' => self::TYPE_STRING,
                'description' => 'Operating system version.',
                'default' => '',
                'example' => 'Mac',
            ])
            ->addRule('clientType', [
                'type' => self::TYPE_STRING,
                'description' => 'Client type.',
                'default' => '',
                'example' => 'browser',
            ])
            ->addRule('clientCode', [
                'type' => self::TYPE_STRING,
                'description' => 'Client code name. View list of [available options](https://github.com/appwrite/appwrite/blob/master/docs/lists/clients.json).',
                'default' => '',
                'example' => 'CM',
            ])
            ->addRule('clientName', [
                'type' => self::TYPE_STRING,
                'description' => 'Client name.',
                'default' => '',
                'example' => 'Chrome Mobile iOS',
            ])
            ->addRule('clientVersion', [
                'type' => self::TYPE_STRING,
                'description' => 'Client version.',
                'default' => '',
                'example' => '84.0',
            ])
            ->addRule('clientEngine', [
                'type' => self::TYPE_STRING,
                'description' => 'Client engine name.',
                'default' => '',
                'example' => 'WebKit',
            ])
            ->addRule('clientEngineVersion', [
                'type' => self::TYPE_STRING,
                'description' => 'Client engine name.',
                'default' => '',
                'example' => '605.1.15',
            ])
            ->addRule('deviceName', [
                'type' => self::TYPE_STRING,
                'description' => 'Device name.',
                'default' => '',
                'example' => 'smartphone',
            ])
            ->addRule('deviceBrand', [
                'type' => self::TYPE_STRING,
                'description' => 'Device brand name.',
                'default' => '',
                'example' => 'Google',
            ])
            ->addRule('deviceModel', [
                'type' => self::TYPE_STRING,
                'description' => 'Device model name.',
                'default' => '',
                'example' => 'Nexus 5',
            ])
            ->addRule('countryCode', [
                'type' => self::TYPE_STRING,
                'description' => 'Country two-character ISO 3166-1 alpha code.',
                'default' => '',
                'example' => 'US',
            ])
            ->addRule('countryName', [
                'type' => self::TYPE_STRING,
                'description' => 'Country name.',
                'default' => '',
                'example' => 'United States',
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName():string
    {
        return 'Log';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_LOG;
    }
}
