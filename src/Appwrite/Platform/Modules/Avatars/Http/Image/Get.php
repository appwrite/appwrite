<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Image;

use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\PublicURL;
use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Image\Image;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Psr7\Method as RequestMethod;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Validator\Range;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getImage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/image')
            ->desc('Get image from URL')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('cache', true)
            ->label('cache.resource', 'avatar/image')
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'getImage',
                description: '/docs/references/avatars/get-image.md',
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
            ->param('url', '', new PublicURL(), 'Image URL which you want to crop.')
            ->param('width', 400, new Range(0, 2000), 'Resize preview image width, Pass an integer between 0 to 2000. Defaults to 400.', true)
            ->param('height', 400, new Range(0, 2000), 'Resize preview image height, Pass an integer between 0 to 2000. Defaults to 400.', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $url, int $width, int $height, Response $response)
    {
        $quality = 80;
        $output = 'png';
        $type = 'png';

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        try {
            $res = (new Client(new CurlAdapter()))
                ->withTimeout(15)
                ->sendRequest((new RequestFactory())->createRequest(RequestMethod::GET, $url));
        } catch (\Throwable) {
            throw new Exception(Exception::AVATAR_REMOTE_URL_FAILED);
        }

        if ($res->getStatusCode() !== 200) {
            throw new Exception(Exception::AVATAR_IMAGE_NOT_FOUND);
        }

        try {
            $image = new Image((string) $res->getBody());
        } catch (\ImagickException) {
            throw new Exception(Exception::AVATAR_IMAGE_NOT_FOUND);
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
