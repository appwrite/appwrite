<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
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
        return 'getSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/sessions/:sessionId')
            ->desc('Get session')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'getSession',
                description: '/docs/references/account/get-session.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SESSION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('sessionId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Session ID. Use the string \'current\' to get the current device session.', true, ['dbForProject'])
            ->inject('response')
            ->inject('targetUser')
            ->inject('locale')
            ->inject('session')
            ->callback($this->action(...));
    }

    public function action(
        ?string $sessionId,
        Response $response,
        User $targetUser,
        Locale $locale,
        ?Document $current,
    ): void {
        $sessions = $targetUser->getAttribute('sessions', []);
        // While impersonating, the request runs on the impersonator's session, so 'current'
        // resolves against none of the target's sessions and this throws. That matches the
        // sessions list, which marks none of them current for the same reason.
        $sessionId = ($sessionId === 'current')
            ? $current?->getId()
            : $sessionId;

        foreach ($sessions as $session) {
            /** @var Document $session */
            if ($sessionId === $session->getId()) {
                $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

                $session
                    ->setAttribute('current', $session->getId() === $current?->getId())
                    ->setAttribute('countryName', $countryName)
                    ->setAttribute('secret', $session->getAttribute('secret', ''))
                ;

                $response->dynamic($session, Response::MODEL_SESSION);
                return;
            }
        }

        throw new Exception(Exception::USER_SESSION_NOT_FOUND);
    }
}
