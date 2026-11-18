<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Devices;

use Appwrite\Auth\AuthDevice;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getDevice';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/devices/:deviceId')
            ->desc('Get device')
            ->groups(['api', 'account'])
            // A read, but guests must be able to call it: after the active session is deleted the
            // caller is a guest and reads the device to pick another session. `sessions.write` is
            // the only scope on this service the guest role has (there is no `sessions.read`).
            ->label('scope', 'sessions.write')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'devices',
                name: 'getDevice',
                description: <<<EOT
                Get the account switching device for the current browser, including every session it holds, the name of the user each session belongs to, and the ID of the active session. Use the string 'current' to reference the current browser's device.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DEVICE,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('deviceId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Device ID. Use the string \'current\' to reference the device of the current browser.', true, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authDevice')
            ->inject('locale')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        ?string $deviceId,
        Response $response,
        Database $dbForProject,
        ?Document $authDevice,
        Locale $locale,
        Authorization $authorization,
    ): void {
        if ($authDevice === null) {
            throw new Exception(Exception::USER_DEVICE_NOT_FOUND);
        }

        // A caller may only read its own device, the one the cookie resolves to.
        if ($deviceId !== 'current' && $deviceId !== $authDevice->getId()) {
            throw new Exception(Exception::USER_DEVICE_NOT_FOUND);
        }

        $authDevice = AuthDevice::present($dbForProject, $authorization, $locale, $authDevice);

        $response->dynamic($authDevice, Response::MODEL_DEVICE);
    }
}
