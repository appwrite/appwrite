<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Prefs;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Assoc;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateUserPrefs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/users/:userId/prefs')
            ->desc('Update user preferences')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].update.prefs')
            ->label('scope', 'users.write')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'updatePrefs',
                description: '/docs/references/users/update-user-prefs.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PREFERENCES,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('prefs', '', new Assoc(), 'Prefs key-value JSON object.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $userId, array $prefs, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), new Document(['prefs' => $prefs]));

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($user, Response::MODEL_USER));

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    }
}
