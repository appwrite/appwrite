<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Initials;

use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Imagick;
use ImagickDraw;
use ImagickPixel;
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
        $themes = [
            ['background' => '#FD366E'], // Default (Pink)
            ['background' => '#FE9567'], // Orange
            ['background' => '#7C67FE'], // Purple
            ['background' => '#68A3FE'], // Blue
            ['background' => '#85DBD8'], // Mint
        ];

        $name = (!empty($name)) ? $name : $user->getAttribute('name', $user->getAttribute('email', ''));
        $words = \explode(' ', \strtoupper($name));
        // if there is no space, try to split by `_` underscore
        $words = (count($words) == 1) ? \explode('_', \strtoupper($name)) : $words;

        $initials = '';
        $code = 0;

        foreach ($words as $key => $w) {
            if (ctype_alnum($w[0] ?? '')) {
                $initials .= $w[0];
                $code += ord($w[0]);

                if ($key == 1) {
                    break;
                }
            }
        }

        $rand = \substr($code, -1);

        $rand = ($rand > \count($themes) - 1) ? $rand % \count($themes) : $rand;

        $background = (!empty($background)) ? '#' . $background : $themes[$rand]['background'];

        $image = new Imagick();
        $punch = new Imagick();
        $draw = new ImagickDraw();
        $fontSize = \min($width, $height) / 2;

        $punch->newImage($width, $height, 'transparent');

        $draw->setFont(__DIR__ . "/../../../../../../assets/fonts/inter-v8-latin-regular.woff2");
        $image->setFont(__DIR__ . "/../../../../../../assets/fonts/inter-v8-latin-regular.woff2");

        $draw->setFillColor(new ImagickPixel('black'));
        $draw->setFontSize($fontSize);

        $draw->setTextAlignment(Imagick::ALIGN_CENTER);
        $draw->annotation($width / 1.97, ($height / 2) + ($fontSize / 3), $initials);

        $punch->drawImage($draw);
        $punch->negateImage(true, Imagick::CHANNEL_ALPHA);

        $image->newImage($width, $height, $background);
        $image->setImageFormat("png");
        $image->compositeImage($punch, Imagick::COMPOSITE_COPYOPACITY, 0, 0);

        $response
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->setContentType('image/png')
            ->file($image->getImageBlob());
    }
}
