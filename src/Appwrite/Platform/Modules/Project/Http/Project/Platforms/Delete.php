<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Platforms;

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
        return 'deleteProjectPlatform';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/project/platforms/:platformId')
            ->httpAlias('/v1/projects/:projectId/platforms/:platformId')
            ->desc('Delete project platform')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'platforms.[platformId].delete')
            ->label('audits.event', 'project.platform.delete')
            ->label('audits.resource', 'project.platform/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'platforms',
                name: 'deletePlatform',
                description: <<<EOT
                Delete a platform by its unique ID. This endpoint removes the platform and all its configurations from the project.
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
            ->param('platformId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Platform ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $platformId,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
        Event $queueForEvents,
    ) {
        $platform = $authorization->skip(fn () => $dbForPlatform->getDocument('platforms', $platformId));

        if ($platform->isEmpty() || $platform->getAttribute('projectInternalId', '') !== $project->getSequence()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        if (!$authorization->skip(fn () => $dbForPlatform->deleteDocument('platforms', $platform->getId()))) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove document from DB');
        };

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('platformId', $platform->getId());

        $response->noContent();
    }
}
