<?php
namespace Appwrite\Platform\Modules\DevelopmentKeys\Http;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'delete';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/projects/:projectId/development-keys/:keyId')
            ->desc('Delete key')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.write')
            ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
            ->label('sdk.namespace', 'projects')
            ->label('sdk.method', 'deleteDevelopmentKey')
            ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
            ->label('sdk.response.model', Response::MODEL_NONE)
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

        $dbForConsole->deleteDocument('keys', $key->getId());

        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response->noContent();
    }
}
