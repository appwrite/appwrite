<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class ConsoleOAuth2ProviderList extends Model
{
    public function __construct()
    {
        $this
            ->addRule('total', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of OAuth2 providers exposed by the server.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('oAuth2Providers', [
                'type' => Response::MODEL_CONSOLE_OAUTH2_PROVIDER,
                'description' => 'List of OAuth2 providers, each with the parameters required to configure it.',
                'default' => [],
                'array' => true,
            ])
        ;
    }

    public function getName(): string
    {
        return 'Console OAuth2 Providers List';
    }

    public function getType(): string
    {
        return Response::MODEL_CONSOLE_OAUTH2_PROVIDER_LIST;
    }
}
