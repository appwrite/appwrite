<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Sessions;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listUserSessions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/users/:userId/sessions')
            ->desc('List user sessions')
            ->groups(['api', 'users'])
            ->label('scope', ['users.read', 'sessions.read'])
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'sessions',
                name: 'listSessions',
                description: '/docs/references/users/list-user-sessions.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SESSION_LIST,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('locale')
            ->callback($this->action(...));
    }

    public function action(string $userId, bool $includeTotal, Response $response, Database $dbForProject, Locale $locale): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }
        $sessions = $user->getAttribute('sessions', []);
        foreach ($sessions as $key => $session) {
            /** @var Document $session */
            $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));
            $session->setAttribute('countryName', $countryName);
            $session->setAttribute('current', false);
            $sessions[$key] = $session;
        }

        $response->dynamic(new Document([
            'sessions' => $sessions,
            'total' => $includeTotal ? count($sessions) : 0,
        ]), Response::MODEL_SESSION_LIST);
    }
}
