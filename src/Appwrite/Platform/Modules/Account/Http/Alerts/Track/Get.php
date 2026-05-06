<?php

namespace Appwrite\Platform\Modules\Account\Http\Alerts\Track;

use Ahc\Jwt\JWT;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
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
                auth: [],
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
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $alertId,
        string $jwt,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
    ): void {
        $secret = System::getEnv('_APP_OPENSSL_KEY_V1');

        if ($secret !== '' && $jwt !== '') {
            try {
                $decoder = new JWT($secret, 'HS256', 2592000, 0);
                $decoded = $decoder->decode($jwt);

                if (
                    isset($decoded['alertId'], $decoded['userId'], $decoded['purpose'])
                    && $decoded['purpose'] === 'alert_track'
                    && $decoded['alertId'] === $alertId
                ) {
                    $authorization->skip(function () use ($dbForPlatform, $alertId, $decoded) {
                        $alert = $dbForPlatform->getDocument('alerts', $alertId);

                        if (
                            !$alert->isEmpty()
                            && $alert->getAttribute('userId') === $decoded['userId']
                            && $alert->getAttribute('read') !== true
                        ) {
                            $dbForPlatform->updateDocument('alerts', $alertId, new Document([
                                'read' => true,
                            ]));
                        }
                    });
                }
            } catch (\Throwable) {
                // Silent fail — never reveal JWT validity through response status
            }
        }

        // 1x1 transparent PNG (canonical 67-byte payload)
        $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');

        $response
            ->setContentType('image/png')
            ->addHeader('Cache-Control', 'no-store')
            ->send($pixel);
    }
}
