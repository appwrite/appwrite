<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Gravatar;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Domains\Domain;
use Utopia\Fetch\Client;
use Utopia\Image\Image;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Emails\Validator\Email;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getAvatarsGravatar';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/gravatar')
            ->desc('Get user Gravatar')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'getGravatar',
                description: 'You can use this endpoint to show a Gravatar image for a user by providing their email address. If no email is provided, the Gravatar for the currently authenticated user is returned. The fallback image and maximum rating can be customized.',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'ImpersonateUserId'],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::IMAGE
            ))
            ->param('email', '', new Email(), 'Email address. Pass an email address to fetch the Gravatar image for. When empty, the current user\'s email is used.', true)
            ->param('default', 'mp', new WhiteList(['404', 'mp', 'identicon', 'monsterid', 'wavatar', 'retro', 'robohash', 'blank'], true), 'Default image to return when no Gravatar is found. Defaults to \'mp\'.', true)
            ->param('rating', 'g', new WhiteList(['g', 'pg', 'r', 'x'], true), 'Maximum image rating to return. Defaults to \'g\'.', true)
            ->inject('response')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $email,
        string $default,
        string $rating,
        Response $response,
        Document $user,
    ): void {
        // Resolve the email: explicit param takes priority, then current user's email.
        // If neither is available, the caller must supply one or be signed in.
        if (!empty($email)) {
            $resolvedEmail = $email;
        } elseif (!$user->isEmpty()) {
            $resolvedEmail = $user->getAttribute('email', '');
        } else {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Either the \'email\' param or an active session is required.');
        }

        // Gravatar spec: SHA-256 of strtolower(trim($email))
        $hash = \hash('sha256', \strtolower(\trim($resolvedEmail)));

        $gravatarUrl = 'https://www.gravatar.com/avatar/' . $hash . '?s=256&d=' . \urlencode($default) . '&r=' . \urlencode($rating);

        $client = new Client();
        try {
            $res = $client
                ->setAllowRedirects(true)
                ->fetch($gravatarUrl);
        } catch (\Throwable) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        if ($res->getStatusCode() === 404) {
            throw new Exception(Exception::AVATAR_IMAGE_NOT_FOUND);
        }

        if ($res->getStatusCode() !== 200) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        $response
            ->addHeader('Cache-Control', 'private, max-age=60') // 1 minute
            ->setContentType('image/png')
            ->file($res->getBody());
    }
}
