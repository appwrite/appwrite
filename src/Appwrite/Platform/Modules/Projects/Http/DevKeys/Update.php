<?php

namespace Appwrite\Platform\Modules\Projects\Http\DevKeys;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
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
            ->label('scope', 'devKeys.write')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'devKeys',
                name: 'updateDevKey',
                description: <<<EOT
                Update a project\'s dev key by its unique ID. Use this endpoint to update a project\'s dev key name or expiration time.'
                EOT,
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
            ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
            ->param('expire', null, new DatetimeValidator(), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }
    public function action(string $projectId, string $keyId, string $name, ?string $expire, Response $response, Database $dbForPlatform)
    {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForPlatform->getDocument('devKeys', $keyId);

        if ($key === false || $key->isEmpty() || $key->getAttribute('projectInternalId') !== $project->getSequence()) {
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
