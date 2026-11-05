<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Devices;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
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
            ->label('scope', 'sessions.write')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'devices',
                name: 'getDevice',
                description: <<<EOT
                Get the account switching device for the current browser, including every session it holds and the ID of the active session. Use the string 'current' to reference the current browser's device.
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
            ->inject('device')
            ->inject('locale')
            ->callback($this->action(...));
    }

    public function action(
        ?string $deviceId,
        Response $response,
        ?Document $device,
        Locale $locale,
    ): void {
        if ($device === null) {
            throw new Exception(Exception::DEVICE_NOT_FOUND);
        }

        // A caller may only read its own device, the one the cookie resolves to.
        if ($deviceId !== 'current' && $deviceId !== $device->getId()) {
            throw new Exception(Exception::DEVICE_NOT_FOUND);
        }

        $activeSessionId = $device->getAttribute('activeSessionId', '');
        $sessions = $device->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {
            /** @var Document $session */
            $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

            $session
                ->setAttribute('countryName', $countryName)
                ->setAttribute('current', $session->getId() === $activeSessionId)
                ->setAttribute('secret', $session->getAttribute('secret', ''));

            $sessions[$key] = $session;
        }

        $device->setAttribute('sessions', $sessions);

        $response->dynamic($device, Response::MODEL_DEVICE);
    }
}
