<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Photo;

use Appwrite\AvatarPhotos\Photo;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getPhoto';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/photo')
            ->desc('Get user photo')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'getPhoto',
                description: 'Returns the best available profile photo for the currently authenticated user. '
                    . 'The endpoint tries each source in priority order and returns the first successful result: '
                    . '(1) OAuth2 session photo (planned — see TODO in source), '
                    . '(2) Gravatar, '
                    . '(3) Libravatar, '
                    . '(4) initials generated from the user\'s name or email, '
                    . '(5) a built-in static fallback image.',
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
            ->param('width', 256, new Range(0, 2000), 'Output image width in pixels. Pass an integer between 0 and 2000. Defaults to 256.', true)
            ->param('height', 256, new Range(0, 2000), 'Output image height in pixels. Pass an integer between 0 and 2000. Defaults to 256.', true)
            ->param('quality', 100, new Range(0, 100), 'Output image quality between 0 and 100. Defaults to 100.', true)
            ->param('output', 'png', new WhiteList(['png', 'jpg', 'webp'], true), 'Output image format. Defaults to \'png\'.', true)
            ->param('rating', 'g', new WhiteList(['g', 'pg', 'r', 'x'], true), 'Maximum image rating to fetch from Gravatar/Libravatar. Defaults to \'g\'.', true)
            ->inject('response')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        int $width,
        int $height,
        int $quality,
        string $output,
        string $rating,
        Response $response,
        Document $user,
    ): void {
        $email = $user->getAttribute('email', '');
        $name  = $user->getAttribute('name', '');

        $photo = new Photo($this->getAppRoot());

        $data = $photo->resolve(
            email: $email,
            name: $name,
            width: $width,
            height: $height,
            quality: $quality,
            output: $output,
            rating: $rating,
        );

        $contentType = match ($output) {
            'jpg'  => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        $response
            ->addHeader('Cache-Control', 'private, max-age=60') // 1 minute — photo may change
            ->setContentType($contentType)
            ->file($data);
    }
}
