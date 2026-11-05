<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Clients;

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
        return 'getClient';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/clients/:clientId')
            ->desc('Get client')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'clients',
                name: 'getClient',
                description: <<<EOT
                Get the account switching client of the current device, including every session it holds and the ID of the active session. Use the string 'current' to reference the device's own client.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_CLIENT,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('clientId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Client ID. Use the string \'current\' to reference the current device client.', true, ['dbForProject'])
            ->inject('response')
            ->inject('client')
            ->inject('locale')
            ->callback($this->action(...));
    }

    public function action(
        ?string $clientId,
        Response $response,
        ?Document $client,
        Locale $locale,
    ): void {
        if ($client === null) {
            throw new Exception(Exception::CLIENT_NOT_FOUND);
        }

        // A device may only read its own client, the one the cookie resolves to.
        if ($clientId !== 'current' && $clientId !== $client->getId()) {
            throw new Exception(Exception::CLIENT_NOT_FOUND);
        }

        $activeSessionId = $client->getAttribute('activeSessionId', '');
        $sessions = $client->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {
            /** @var Document $session */
            $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

            $session
                ->setAttribute('countryName', $countryName)
                ->setAttribute('current', $session->getId() === $activeSessionId)
                ->setAttribute('secret', $session->getAttribute('secret', ''));

            $sessions[$key] = $session;
        }

        $client->setAttribute('sessions', $sessions);

        $response->dynamic($client, Response::MODEL_CLIENT);
    }
}
