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

class XList extends Action
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
                description: <<<EOT
                List all the project\'s dev keys. Dev keys are project specific and allow you to bypass rate limits and get better error logging during development.'
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DEV_KEY_LIST
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback([$this, 'action']);
    }

    public function action(string $projectId, Response $response, Database $dbForPlatform)
    {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $keys = $dbForPlatform->find('devKeys', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::limit(APP_LIMIT_DEV_KEYS),
        ]);

        $response->dynamic(new Document([
            'keys' => $keys,
            'total' => count($keys),
        ]), Response::MODEL_DEV_KEY_LIST);
    }
}
