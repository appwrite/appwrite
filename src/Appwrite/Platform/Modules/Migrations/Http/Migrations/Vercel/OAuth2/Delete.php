<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Vercel\OAuth2;

use Appwrite\Platform\Modules\Migrations\Http\Migrations\Vercel\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Client\Client;
use Utopia\Database\Database;

class Delete extends Base
{
    public static function getName(): string
    {
        return 'deleteVercelOAuth2';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/migrations/vercel/oauth2')
            ->desc('Disconnect Vercel account')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'deleteVercelOAuth2',
                description: <<<EOT
                Uninstall Appwrite's Vercel integration from the connected Vercel account and forget the installation.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->inject('response')
            ->inject('user')
            ->inject('dbForPlatform')
            ->inject('clientForOAuth2')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        User $user,
        Database $dbForPlatform,
        Client $clientForOAuth2,
    ): void {
        $identity = $this->getIdentity($dbForPlatform, $user);

        try {
            $accessToken = $this->getAccessToken($clientForOAuth2, $dbForPlatform, $identity);
            $teamId = $identity->getAttribute('secrets', [])['teamId'] ?? '';
            $this->request($clientForOAuth2, 'DELETE', '/v1/integrations/configuration/' . \rawurlencode($identity->getAttribute('providerUid', '')) . ($teamId === '' ? '' : '?' . \http_build_query(['teamId' => $teamId])), [
                'Authorization' => 'Bearer ' . $accessToken,
            ]);
        } catch (\Throwable) {
            // Already uninstalled on Vercel; the installation is forgotten either way
        }

        $dbForPlatform->deleteDocument('identities', $identity->getId());

        $response->noContent();
    }
}
