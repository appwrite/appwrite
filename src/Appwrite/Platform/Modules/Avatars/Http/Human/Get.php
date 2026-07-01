<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Human;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Avatars\Avatars;
use Utopia\Avatars\Exception\InvalidIdentifier;
use Utopia\Avatars\Exception\NotFound;
use Utopia\Avatars\Human;
use Utopia\Emails\Validator\Email;
use Utopia\Image\Image;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getHuman';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/human')
            ->desc('Get human avatar')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('cache', true)
            ->label('cache.resource', 'avatar/human')
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'getHuman',
                description: '/docs/references/avatars/get-human.md',
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
            ->param('github', '', new Text(39), 'GitHub username.', true)
            ->param('email', '', new Email(), 'User email for Gravatar. The email is hashed server-side using MD5.', true)
            ->param('emailHash', '', new Text(32), 'MD5 hash of the user email for Gravatar. Takes precedence over `email`.', true)
            ->param('width', 400, new Range(0, 2000), 'Resize preview image width. Pass an integer between 0 to 2000. Defaults to 400.', true)
            ->param('height', 400, new Range(0, 2000), 'Resize preview image height. Pass an integer between 0 to 2000. Defaults to 400.', true)
            ->inject('response')
            ->inject('avatars')
            ->callback($this->action(...));
    }

    public function action(string $github, string $email, string $emailHash, int $width, int $height, Response $response, Avatars $avatars): void
    {
        $quality = 80;
        $output = 'png';

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        $fetchSize = \max($width, $height);
        if ($fetchSize === 0) {
            $fetchSize = 512;
        }

        try {
            $imageData = $avatars->getHuman(new Human(
                github: $github,
                email: $email,
                emailHash: $emailHash,
            ), $fetchSize);
        } catch (InvalidIdentifier $error) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $error->getMessage());
        } catch (NotFound) {
            throw new Exception(Exception::AVATAR_IMAGE_NOT_FOUND);
        }

        try {
            $image = new Image($imageData);
        } catch (\Throwable) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unable to parse image');
        }

        $image->crop((int) $width, (int) $height);
        $data = $image->output($output, $quality);

        $response
            ->addHeader('Cache-Control', 'private, max-age=2592000') // 30 days
            ->setContentType('image/png')
            ->file($data);
        unset($image);
    }
}
