<?php

namespace Appwrite\Platform\Modules\Projects\Http\DevKeys;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class ListDevKeys extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'listDevKeys';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/projects/:projectId/dev-keys')
            ->desc('List dev keys')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.read')
            ->label('sdk', new Method(
                namespace: 'projects',
                name: 'listDevKeys',
                description: '',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DEV_KEY_LIST
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback(fn ($projectId, $response, $dbForPlatform) => $this->action($projectId, $response, $dbForPlatform));
    }

    public function action(string $projectId, Response $response, Database $dbForPlatform)
    {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $keys = $dbForPlatform->find('devKeys', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'keys' => $keys,
            'total' => count($keys),
        ]), Response::MODEL_DEV_KEY_LIST);
    }
}
