<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listSessions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/sessions')
            ->desc('List sessions')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'listSessions',
                description: '/docs/references/account/list-sessions.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SESSION_LIST,
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('targetUser')
            ->inject('locale')
            ->inject('session')
            ->callback($this->action(...));
    }

    public function action(
        bool $includeTotal,
        Response $response,
        User $targetUser,
        Locale $locale,
        ?Document $current,
    ): void {
        $sessions = $targetUser->getAttribute('sessions', []);
        // While impersonating, the request runs on the impersonator's session, so none of
        // the target's sessions is marked current.
        $currentId = $current?->getId();

        foreach ($sessions as $key => $session) {
            /** @var Document $session */
            $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

            $session->setAttribute('countryName', $countryName);
            $session->setAttribute('current', $currentId === $session->getId());
            $session->setAttribute('secret', $session->getAttribute('secret', ''));

            $sessions[$key] = $session;
        }

        $response->dynamic(new Document([
            'sessions' => $sessions,
            'total' => $includeTotal ? count($sessions) : 0,
        ]), Response::MODEL_SESSION_LIST);
    }
}
