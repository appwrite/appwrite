<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ConsoleOAuth2ProviderParameter extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Parameter ID. Maps to the request body field used by the project OAuth2 update endpoint (e.g. `clientId`, `appKey`, `tenant`).',
                'default' => '',
                'example' => 'clientId',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Verbose, user-facing parameter name as shown in the provider\'s own dashboard. Includes alternate names when the provider exposes more than one.',
                'default' => '',
                'example' => 'Client ID or App ID',
            ])
            ->addRule('example', [
                'type' => self::TYPE_STRING,
                'description' => 'Example value for this parameter.',
                'default' => '',
                'example' => 'e4d87900000000540733',
            ])
            ->addRule('hint', [
                'type' => self::TYPE_STRING,
                'description' => 'Optional hint for this parameter, typically calling out a common wrong value. Empty string when no hint is set.',
                'default' => '',
                'example' => 'Example of wrong value: 370006',
            ])
        ;
    }

    public function getName(): string
    {
        return 'Console OAuth2 Provider Parameter';
    }

    public function getType(): string
    {
        return Response::MODEL_CONSOLE_OAUTH2_PROVIDER_PARAMETER;
    }
}
