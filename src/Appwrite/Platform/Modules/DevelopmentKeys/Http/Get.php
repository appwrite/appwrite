<?php

namespace Appwrite\Platform\Modules\DevelopmentKeys\Http;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'get';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/projects/:projectId/development-keys/:keyId')
            ->desc('Get key')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.read')
            ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
            ->label('sdk.namespace', 'projects')
            ->label('sdk.method', 'getDevelopmentKey')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_KEY)
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('keyId', '', new UID(), 'Key unique ID.')
            ->inject('response')
            ->inject('dbForConsole')
            ->callback(fn ($projectId, $keyId, $response, $dbForConsole) => $this->action($projectId, $keyId, $response, $dbForConsole));
    }

    public function action(string $projectId, string $keyId, Response $response, Database $dbForConsole)
    {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForConsole->findOne('developmentKeys', [
            Query::equal('$id', [$keyId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($key === false || $key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $response->dynamic($key, Response::MODEL_KEY);
    }
}
