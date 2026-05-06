<?php

namespace Appwrite\Platform\Modules\Account\Http\Alerts\Track;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getAlertTrack';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/alerts/:alertId/track')
            ->desc('Track alert')
            ->groups(['api', 'account'])
            ->label('scope', 'public')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'alerts',
                name: 'getAlertTrack',
                description: '/docs/references/account/get-alert-track.md',
                auth: [AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::IMAGE_PNG,
            ))
            ->param('alertId', '', new UID(), 'Alert ID.')
            ->param('jwt', '', new Text(2048, 0), 'Tracking token.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $alertId,
        string $jwt,
        Response $response,
        Database $dbForPlatform,
    ): void {
        // 1x1 transparent PNG. Wave 3 (ST14) will replace this body with
        // JWT decode + read-flag flip; the skeleton always returns the pixel.
        $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');

        $response
            ->setContentType('image/png')
            ->addHeader('Cache-Control', 'no-store')
            ->send($pixel);
    }
}
