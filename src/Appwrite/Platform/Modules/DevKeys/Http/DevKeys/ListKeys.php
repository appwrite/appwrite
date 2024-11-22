<?php

namespace Appwrite\Platform\Modules\DevKeys\Http\DevKeys;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class ListKeys extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'listKeys';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/projects/:projectId/development-keys')
            ->desc('List keys')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.read')
            ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
            ->label('sdk.namespace', 'projects')
            ->label('sdk.method', 'listKeys')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_KEY_LIST)
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->inject('response')
            ->inject('dbForConsole')
            ->callback(fn ($projectId, $response, $dbForConsole) => $this->action($projectId, $response, $dbForConsole));
    }

    public function action(string $projectId, Response $response, Database $dbForConsole)
    {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $keys = $dbForConsole->find('devKeys', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'keys' => $keys,
            'total' => count($keys),
        ]), Response::MODEL_KEY_LIST);
    }
}
