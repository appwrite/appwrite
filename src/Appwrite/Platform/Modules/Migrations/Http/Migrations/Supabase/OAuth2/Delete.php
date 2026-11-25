<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\OAuth2;

use Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Client\Client;
use Utopia\Database\Database;

class Delete extends Base
{
    public static function getName(): string
    {
        return 'deleteSupabaseOAuth2';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/migrations/supabase/oauth2')
            ->desc('Disconnect Supabase account')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'deleteSupabaseOAuth2',
                description: <<<EOT
                Revoke Appwrite's access to the connected Supabase account and forget its tokens.
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
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForPlatform')
            ->inject('clientForOAuth2')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response,
        User $user,
        Database $dbForPlatform,
        Client $clientForOAuth2,
    ): void {
        $identity = $this->getIdentity($dbForPlatform, $user);

        try {
            $this->oauth2($clientForOAuth2, $request)->revokeTokens($identity->getAttribute('providerRefreshToken', ''));
        } catch (\Throwable) {
            // Already revoked on Supabase; the tokens are forgotten either way
        }

        $dbForPlatform->deleteDocument('identities', $identity->getId());

        $response->noContent();
    }
}
