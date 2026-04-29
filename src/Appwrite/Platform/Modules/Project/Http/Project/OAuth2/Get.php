<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getProjectOAuth2';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/oauth2/:provider')
            ->desc('Get project OAuth2 provider')
            ->groups(['api', 'project'])
            ->label('scope', 'oauth2.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'oauth2',
                name: 'getOAuth2Provider',
                description: <<<EOT
                Get a single OAuth2 provider configuration. Credential fields (client secret, p8 file, key/team IDs) are write-only and always returned empty.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: [
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
                    )
                ]
            ))
            ->param('provider', '', new Text(128), 'OAuth2 provider key. For example: github, google, apple.')
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $provider,
        Response $response,
        Document $project,
    ): void {
        $providers = Config::getParam('oAuthProviders', []);
        if (!\array_key_exists($provider, $providers) || !($providers[$provider]['enabled'] ?? false)) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        $actions = Base::getProviderActions();
        if (!isset($actions[$provider])) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        $updateClass = $actions[$provider];
        $action = new $updateClass();

        $response->dynamic($action->buildReadResponse($project), $updateClass::getResponseModel());
    }
}
