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
        return 'getGravatar';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/gravatar')
            ->desc('Get user Gravatar')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('cache', true)
            ->label('cache.resource', 'avatar/gravatar')
            ->label('cache.params', ['email', 'width', 'height', 'default', 'rating', 'project'])
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'getGravatar',
                description: '/docs/references/avatars/get-gravatar.md',
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
            ->param('width', 80, new Range(1, 2048), 'Image width. Pass an integer between 1 to 2048. Defaults to 80.', true)
            ->param('height', 80, new Range(1, 2048), 'Image height. Pass an integer between 1 to 2048. Defaults to 80.', true)
            ->param('default', 'mp', new WhiteList(['404', 'mp', 'identicon', 'monsterid', 'wavatar', 'retro', 'robohash', 'blank'], true), 'Default image to return when no Gravatar is found. Defaults to \'mp\'.', true)
            ->param('rating', 'g', new WhiteList(['g', 'pg', 'r', 'x'], true), 'Maximum image rating to return. Defaults to \'g\'.', true)
            ->inject('response')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $email,
        int $width,
        int $height,
        string $default,
        string $rating,
        Response $response,
        Document $user,
    ): void {
        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

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

        $gravatarUrl = \sprintf(
            'https://www.gravatar.com/avatar/%s?s=%d&d=%s&r=%s',
            $hash,
            $width,
            \urlencode($default),
            \urlencode($rating),
        );

        $host = \parse_url($gravatarUrl, PHP_URL_HOST) ?? '';

        $isIpLiteral = \filter_var(\trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
        if (!$isIpLiteral) {
            $domain = new Domain($host);
            if (!$domain->isKnown()) {
                throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
            }
        }

        $hostnameValidator = new PublicHostname();
        if (!$hostnameValidator->isValid($host)) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED, $hostnameValidator->getDescription());
        }

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

        try {
            $image = new Image($res->getBody());
        } catch (\ImagickException) {
            throw new Exception(Exception::AVATAR_IMAGE_NOT_FOUND);
        }

        $image->crop($width, $height);
        $data = $image->output('png', 80);

        $response
            ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
            ->setContentType('image/png')
            ->file($data);
        unset($image);
    }
}
