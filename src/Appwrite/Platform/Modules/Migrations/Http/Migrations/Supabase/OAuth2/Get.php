<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\OAuth2;

use Appwrite\Auth\EncryptionKey;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Supabase\Base;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Client\Client;

class Get extends Base
{
    public static function getName(): string
    {
        return 'getSupabaseOAuth2';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/supabase/oauth2')
            ->desc('Connect Supabase account')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('docs', false)
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->param('success', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to after Supabase grants access. Only console hostnames are allowed.', false, ['redirectValidator'])
            ->param('failure', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect back to when Supabase access is not granted. Only console hostnames are allowed.', false, ['redirectValidator'])
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('clientForOAuth2')
            ->callback($this->action(...));
    }

    public function action(
        string $success,
        string $failure,
        Request $request,
        Response $response,
        User $user,
        Client $clientForOAuth2,
    ): void {
        // The state is echoed back untouched, so it is bound to the signed in user: the
        // callback refuses a state started by anyone else, which stops one user linking
        // their Supabase account to another user's console
        $nonce = \bin2hex(\random_bytes(16));

        $oauth2 = $this->oauth2($clientForOAuth2, $request, [
            'success' => $success,
            'failure' => $failure,
            'nonce' => $nonce,
            'binding' => \hash_hmac('sha256', $nonce . ':' . $user->getId(), EncryptionKey::current()),
        ]);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($oauth2->getLoginURL());
    }
}
