<?php

namespace Appwrite\Platform\Modules\Avatars\Http\CreditCards;

use Appwrite\Platform\Modules\Avatars\Http\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getCreditCard';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/avatars/credit-cards/:code')
            ->desc('Get credit card icon')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.read')
            ->label('cache', true)
            ->label('cache.resource', 'avatar/credit-card')
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'getCreditCard',
                description: '/docs/references/avatars/get-credit-card.md',
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
            ->param('code', '', new WhiteList(\array_keys(Config::getParam('avatar-credit-cards'))), 'Credit Card Code. Possible values: ' . \implode(', ', \array_keys(Config::getParam('avatar-credit-cards'))) . '.')
            ->param('width', 100, new Range(0, 2000), 'Image width. Pass an integer between 0 to 2000. Defaults to 100.', true)
            ->param('height', 100, new Range(0, 2000), 'Image height. Pass an integer between 0 to 2000. Defaults to 100.', true)
            ->param('quality', -1, new Range(-1, 100), 'Image quality. Pass an integer between 0 to 100. Defaults to keep existing image quality.', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $code, int $width, int $height, int $quality, Response $response)
    {
        $this->avatarCallback('credit-cards', $code, $width, $height, $quality, $response);
    }
}
