<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Vercel\OAuth2;

use Appwrite\Auth\EncryptionKey;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Vercel\Base;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\System\System;

class Get extends Base
{
    public static function getName(): string
    {
        return 'getVercelOAuth2';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/vercel/oauth2')
            ->desc('Connect Vercel account')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('docs', false)
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->param('success', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to after the Vercel integration is installed. Only console hostnames are allowed.', false, ['redirectValidator'])
            ->param('failure', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to when the Vercel integration is not installed. Only console hostnames are allowed.', false, ['redirectValidator'])
            ->inject('response')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $success,
        string $failure,
        Response $response,
        User $user,
    ): void {
        $this->getClient();

        // The state is echoed back untouched, so it is bound to the signed in user: the
        // callback refuses a state started by anyone else, which stops one user linking
        // their Vercel account to another user's console
        $nonce = \bin2hex(\random_bytes(16));
        $state = [
            'success' => $success,
            'failure' => $failure,
            'nonce' => $nonce,
            'binding' => \hash_hmac('sha256', $nonce . ':' . $user->getId(), EncryptionKey::current()),
        ];

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect('https://vercel.com/integrations/' . \rawurlencode(System::getEnv('_APP_MIGRATIONS_VERCEL_INTEGRATION', '')) . '/new?' . \http_build_query([
                'state' => \json_encode($state),
            ]));
    }
}
