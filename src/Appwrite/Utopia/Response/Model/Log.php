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
                'type' => 'string',
                'description' => 'Event name.',
                'example' => 'account.sessions.create',
            ])
            ->addRule('ip', [
                'type' => 'string',
                'description' => 'IP session in use when the session was created.',
                'example' => '127.0.0.1',
            ])
            ->addRule('time', [
                'type' => 'integer',
                'description' => 'Log creation time in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('osCode', [
                'type' => 'string',
                'description' => 'Operating system code name. View list of [available options](https://github.com/appwrite/appwrite/blob/master/docs/lists/os.json).',
                'default' => '',
                'example' => 'Mac',
            ])
            ->addRule('osName', [
                'type' => 'string',
                'description' => 'Operating system name.',
                'default' => '',
                'example' => 'Mac',
            ])
            ->addRule('osVersion', [
                'type' => 'string',
                'description' => 'Operating system version.',
                'default' => '',
                'example' => 'Mac',
            ])
            ->addRule('clientType', [
                'type' => 'string',
                'description' => 'Client type.',
                'default' => '',
                'example' => 'browser',
            ])
            ->addRule('clientCode', [
                'type' => 'string',
                'description' => 'Client code name. View list of [available options](https://github.com/appwrite/appwrite/blob/master/docs/lists/clients.json).',
                'default' => '',
                'example' => 'CM',
            ])
            ->addRule('clientName', [
                'type' => 'string',
                'description' => 'Client name.',
                'default' => '',
                'example' => 'Chrome Mobile iOS',
            ])
            ->addRule('clientVersion', [
                'type' => 'string',
                'description' => 'Client version.',
                'default' => '',
                'example' => '84.0',
            ])
            ->addRule('clientEngine', [
                'type' => 'string',
                'description' => 'Client engine name.',
                'default' => '',
                'example' => 'WebKit',
            ])
            ->addRule('clientEngineVersion', [
                'type' => 'string',
                'description' => 'Client engine name.',
                'default' => '',
                'example' => '605.1.15',
            ])
            ->addRule('deviceName', [
                'type' => 'string',
                'description' => 'Device name.',
                'default' => '',
                'example' => 'smartphone',
            ])
            ->addRule('deviceBrand', [
                'type' => 'string',
                'description' => 'Device brand name.',
                'default' => '',
                'example' => 'Google',
            ])
            ->addRule('deviceModel', [
                'type' => 'string',
                'description' => 'Device model name.',
                'default' => '',
                'example' => 'Nexus 5',
            ])
            ->addRule('countryCode', [
                'type' => 'string',
                'description' => 'Country two-character ISO 3166-1 alpha code.',
                'default' => '',
                'example' => 'US',
            ])
            ->addRule('countryName', [
                'type' => 'string',
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