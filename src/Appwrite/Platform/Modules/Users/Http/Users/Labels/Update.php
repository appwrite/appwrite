<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Labels;

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
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateUserLabels';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/users/:userId/labels')
            ->desc('Update user labels')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].update.labels')
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'updateLabels',
                description: '/docs/references/users/update-user-labels.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('labels', [], new ArrayList(new Text(36, allowList: [...Text::NUMBERS, ...Text::ALPHABET_UPPER, ...Text::ALPHABET_LOWER]), APP_LIMIT_ARRAY_LABELS_SIZE), 'Array of user labels. Replaces the previous labels. Maximum of ' . APP_LIMIT_ARRAY_LABELS_SIZE . ' labels are allowed, each up to 36 alphanumeric characters long.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $userId, array $labels, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user->setAttribute('labels', (array) \array_values(\array_unique($labels)));

        $user = $dbForProject->updateDocument('users', $user->getId(), new Document(['labels' => $user->getAttribute('labels')]));

        $queueForEvents
            ->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    }
}
