<?php

namespace Appwrite\Platform\Modules\Console\Http\OAuth2Providers;

use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base as OAuth2Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listOAuth2Providers';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/console/oauth2-providers')
            ->desc('List OAuth2 providers')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('sdk', new Method(
                namespace: 'console',
                group: 'console',
                name: 'listOAuth2Providers',
                description: 'List all OAuth2 providers supported by the Appwrite server, along with the parameters required to configure each provider. The response excludes mock providers but includes sandbox providers.',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_CONSOLE_OAUTH2_PROVIDER_LIST,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $providersConfig = Config::getParam('oAuthProviders', []);
        $actions = OAuth2Base::getProviderActions();

        $providers = [];
        foreach ($actions as $providerId => $updateClass) {
            $config = $providersConfig[$providerId] ?? null;
            if ($config === null) {
                continue;
            }
            if (!($config['enabled'] ?? false)) {
                continue;
            }
            if ($config['mock'] ?? false) {
                continue;
            }

            $providers[] = new Document([
                '$id' => $providerId,
                'parameters' => $updateClass::getParameters(),
            ]);
        }

        $response->dynamic(new Document([
            'total' => \count($providers),
            'oAuth2Providers' => $providers,
        ]), Response::MODEL_CONSOLE_OAUTH2_PROVIDER_LIST);
    }
}
