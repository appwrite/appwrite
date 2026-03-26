<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Keys;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
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

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deleteProjectKey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/project/keys/:keyId')
            ->httpAlias('/v1/projects/:projectId/keys/:keyId')
            ->desc('Delete project key')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'keys.[keyId].delete')
            ->label('audits.event', 'project.key.delete')
            ->label('audits.resource', 'project.key/{request.keyId}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'keys',
                name: 'deleteKey',
                description: <<<EOT
                Delete a key by its unique ID. Once deleted, the key can no longer be used to authenticate API calls.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('keyId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Key ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $keyId,
        Response $response,
        Database $dbForPlatform,
        Event $queueForEvents,
        Document $project,
        Authorization $authorization,
    ) {
        $key = $authorization->skip(fn () => $dbForPlatform->getDocument('keys', $keyId));

        if ($key->isEmpty() || $key->getAttribute('resourceType', '') !== 'projects' || $key->getAttribute('resourceInternalId', '') !== $project->getSequence()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        if (!$authorization->skip(fn () => $dbForPlatform->deleteDocument('keys', $key->getId()))) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove document from DB');
        };

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('keyId', $key->getId());

        $response->noContent();
    }
}
