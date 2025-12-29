<?php

namespace Appwrite\Platform\Modules\Projects\Http\DevKeys;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'deleteDevKey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/projects/:projectId/dev-keys/:keyId')
            ->desc('Delete dev key')
            ->groups(['api', 'projects'])
            ->label('scope', 'devKeys.write')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'devKeys',
                name: 'deleteDevKey',
                description: <<<EOT
                Delete a project\'s dev key by its unique ID. Once deleted, the key will no longer allow bypassing of rate limits and better logging of errors.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('keyId', '', new UID(), 'Key unique ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $keyId, Response $response, Database $dbForPlatform)
    {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForPlatform->getDocument('devKeys', $keyId);

        if ($key === false || $key->isEmpty() || $key->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $dbForPlatform->deleteDocument('devKeys', $key->getId());

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response->noContent();
    }
}
