<?php

namespace Appwrite\Platform\Modules\DevKeys\Http\DevKeys;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class UpdateKey extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'updateKey';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/projects/:projectId/development-keys/:keyId')
            ->desc('Update dev key')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.write')
            ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
            ->label('sdk.namespace', 'projects')
            ->label('sdk.method', 'updateDevKey')
            ->label('sdk.response.code', Response::STATUS_CODE_OK)
            ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
            ->label('sdk.response.model', Response::MODEL_DEV_KEY)
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('keyId', '', new UID(), 'Key unique ID.')
            ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
            ->param('expire', null, new DatetimeValidator(), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format.')
            ->inject('response')
            ->inject('dbForConsole')
            ->callback(fn ($projectId, $keyId, $name, $expire, $response, $dbForConsole) => $this->action($projectId, $keyId, $name, $expire, $response, $dbForConsole));
    }
    public function action(string $projectId, string $keyId, string $name, ?string $expire, Response $response, Database $dbForConsole)
    {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForConsole->findOne('devKeys', [
            Query::equal('$id', [$keyId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($key === false || $key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $key
            ->setAttribute('name', $name)
            ->setAttribute('expire', $expire);

        $dbForConsole->updateDocument('devKeys', $key->getId(), $key);

        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response->dynamic($key, Response::MODEL_DEV_KEY);
    }
}
