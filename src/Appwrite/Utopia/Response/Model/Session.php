<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Session extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Session ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Session creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('userId', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID.',
                'default' => '',
                'example' => '5e5bb8c16897e',
            ])
            ->addRule('expire', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Session expiration date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('provider', [
                'type' => self::TYPE_STRING,
                'description' => 'Session Provider.',
                'default' => '',
                'example' => 'email',
            ])
            ->addRule('providerUid', [
                'type' => self::TYPE_STRING,
                'description' => 'Session Provider User ID.',
                'default' => '',
                'example' => 'user@example.com',
            ])
            ->addRule('providerAccessToken', [
                'type' => self::TYPE_STRING,
                'description' => 'Session Provider Access Token.',
                'default' => '',
                'example' => 'MTQ0NjJkZmQ5OTM2NDE1ZTZjNGZmZjI3',
            ])
            ->addRule('providerAccessTokenExpiry', [
                'type' => self::TYPE_DATETIME,
                'description' => 'The date of when the access token expires in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('providerRefreshToken', [
                'type' => self::TYPE_STRING,
                'description' => 'Session Provider Refresh Token.',
                'default' => '',
                'example' => 'MTQ0NjJkZmQ5OTM2NDE1ZTZjNGZmZjI3',
            ])
            ->addRule('ip', [
                'type' => self::TYPE_STRING,
                'description' => 'IP in use when the session was created.',
                'default' => '',
                'example' => '127.0.0.1',
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
            ->addRule('current', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Returns true if this the current user session.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Secret used to authenticate the user.',
                'default' => '',
                'example' => '5e5bb8c16897e',
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Session';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_SESSION;
    }
}
