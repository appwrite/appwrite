<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Identities;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteIdentity';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/users/identities/:identityId')
            ->desc('Delete identity')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].identities.[identityId].delete')
            ->label('scope', 'users.write')
            ->label('audits.event', 'identity.delete')
            ->label('audits.resource', 'identity/{request.$identityId}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'identities',
                name: 'deleteIdentity',
                description: '/docs/references/users/delete-identity.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
            ))
            ->param('identityId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Identity ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $identityId, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $identity = $dbForProject->getDocument('identities', $identityId);

        if ($identity->isEmpty()) {
            throw new Exception(Exception::USER_IDENTITY_NOT_FOUND);
        }

        $dbForProject->deleteDocument('identities', $identityId);

        $queueForEvents
            ->setParam('userId', $identity->getAttribute('userId'))
            ->setParam('identityId', $identity->getId())
            ->setPayload($response->output($identity, Response::MODEL_IDENTITY));

        $response->noContent();
    }
}
