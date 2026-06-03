<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class OAuth2ProviderList extends Model
{
    public function __construct()
    {
        $this
            ->addRule('total', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of OAuth2 providers in the given project.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('providers', [
                'type' => [
                    Response::MODEL_OAUTH2_GITHUB,
                    Response::MODEL_OAUTH2_DISCORD,
                    Response::MODEL_OAUTH2_FIGMA,
                    Response::MODEL_OAUTH2_DROPBOX,
                    Response::MODEL_OAUTH2_DAILYMOTION,
                    Response::MODEL_OAUTH2_BITBUCKET,
                    Response::MODEL_OAUTH2_BITLY,
                    Response::MODEL_OAUTH2_BOX,
                    Response::MODEL_OAUTH2_AUTODESK,
                    Response::MODEL_OAUTH2_GOOGLE,
                    Response::MODEL_OAUTH2_ZOOM,
                    Response::MODEL_OAUTH2_ZOHO,
                    Response::MODEL_OAUTH2_YANDEX,
                    Response::MODEL_OAUTH2_X,
                    Response::MODEL_OAUTH2_WORDPRESS,
                    Response::MODEL_OAUTH2_TWITCH,
                    Response::MODEL_OAUTH2_STRIPE,
                    Response::MODEL_OAUTH2_SPOTIFY,
                    Response::MODEL_OAUTH2_SLACK,
                    Response::MODEL_OAUTH2_PODIO,
                    Response::MODEL_OAUTH2_NOTION,
                    Response::MODEL_OAUTH2_SALESFORCE,
                    Response::MODEL_OAUTH2_YAHOO,
                    Response::MODEL_OAUTH2_LINKEDIN,
                    Response::MODEL_OAUTH2_DISQUS,
                    Response::MODEL_OAUTH2_AMAZON,
                    Response::MODEL_OAUTH2_ETSY,
                    Response::MODEL_OAUTH2_FACEBOOK,
                    Response::MODEL_OAUTH2_TRADESHIFT,
                    Response::MODEL_OAUTH2_PAYPAL,
                    Response::MODEL_OAUTH2_GITLAB,
                    Response::MODEL_OAUTH2_AUTHENTIK,
                    Response::MODEL_OAUTH2_AUTH0,
                    Response::MODEL_OAUTH2_FUSIONAUTH,
                    Response::MODEL_OAUTH2_KEYCLOAK,
                    Response::MODEL_OAUTH2_OIDC,
                    Response::MODEL_OAUTH2_APPLE,
                    Response::MODEL_OAUTH2_OKTA,
                    Response::MODEL_OAUTH2_KICK,
                    Response::MODEL_OAUTH2_MICROSOFT,
                ],
                'description' => 'List of OAuth2 providers.',
                'default' => [],
                'array' => true,
            ])
        ;
    }

    public function getName(): string
    {
        return 'OAuth2 Providers List';
    }

    public function getType(): string
    {
        return Response::MODEL_OAUTH2_PROVIDER_LIST;
    }
}
