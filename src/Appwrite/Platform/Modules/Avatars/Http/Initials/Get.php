<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Initials;

use Appwrite\AvatarPhotos\Providers\Initials;
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
use Utopia\Validator\HexColor;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getInitials';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/initials')
            ->desc('Get user initials')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('cache.resource', 'avatar/initials')
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'getInitials',
                description: '/docs/references/avatars/get-initials.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'ImpersonateUserId'],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::IMAGE_PNG
            ))
            ->param('name', '', new Text(128), 'Full Name. When empty, current user name or email will be used. Max length: 128 chars.', true)
            ->param('width', 500, new Range(0, 2000), 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
            ->param('height', 500, new Range(0, 2000), 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
            ->param('background', '', new HexColor(), 'Changes background color. By default a random color will be picked and stay will persistent to the given name.', true)
            ->inject('response')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(string $name, int $width, int $height, string $background, Response $response, Document $user)
    {
        $name = (!empty($name)) ? $name : $user->getAttribute('name', $user->getAttribute('email', ''));

        $image = (new Initials($background))->render($name, $width, $height);

        $response
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->setContentType('image/png')
            ->file($image);
    }
}
