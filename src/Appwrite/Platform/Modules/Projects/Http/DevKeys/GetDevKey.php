<?php

namespace Appwrite\Platform\Modules\Projects\Http\DevKeys;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class GetDevKey extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'getDevKey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/projects/:projectId/dev-keys/:keyId')
            ->desc('Get dev key')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.read')
            ->label('sdk', new Method(
                namespace: 'projects',
                name: 'getDevKey',
                description: '',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DEV_KEY
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('keyId', '', new UID(), 'Key unique ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback(fn ($projectId, $keyId, $response, $dbForPlatform) => $this->action($projectId, $keyId, $response, $dbForPlatform));
    }

    public function action(string $projectId, string $keyId, Response $response, Database $dbForPlatform)
    {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForPlatform->findOne('devKeys', [
            Query::equal('$id', [$keyId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($key === false || $key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $response->dynamic($key, Response::MODEL_DEV_KEY);
    }
}
