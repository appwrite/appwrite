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
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'updateDevKey';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/projects/:projectId/dev-keys/:keyId')
            ->desc('Update dev key')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.write')
            ->label('sdk', new Method(
                namespace: 'projects',
                name: 'updateDevKey',
                description: '/docs/references/projects/update-dev-key.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_DEV_KEY
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('keyId', '', new UID(), 'Key unique ID.')
            ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
            ->param('expire', null, new DatetimeValidator(), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback(fn ($projectId, $keyId, $name, $expire, $response, $dbForPlatform) => $this->action($projectId, $keyId, $name, $expire, $response, $dbForPlatform));
    }
    public function action(string $projectId, string $keyId, string $name, ?string $expire, Response $response, Database $dbForPlatform)
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

        $key
            ->setAttribute('name', $name)
            ->setAttribute('expire', $expire);

        $dbForPlatform->updateDocument('devKeys', $key->getId(), $key);

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response->dynamic($key, Response::MODEL_DEV_KEY);
    }
}
