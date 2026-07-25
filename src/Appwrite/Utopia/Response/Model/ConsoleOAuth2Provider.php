<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ConsoleOAuth2Provider extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'OAuth2 provider ID.',
                'default' => '',
                'example' => 'github',
            ])
            ->addRule('parameters', [
                'type' => Response::MODEL_CONSOLE_OAUTH2_PROVIDER_PARAMETER,
                'description' => 'List of parameters required to configure this OAuth2 provider.',
                'default' => [],
                'array' => true,
            ])
        ;
    }

    public function getName(): string
    {
        return 'Console OAuth2 Provider';
    }

    public function getType(): string
    {
        return Response::MODEL_CONSOLE_OAUTH2_PROVIDER;
    }
}
