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
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;
    public static function getName()
    {
        return 'createDevKey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/projects/:projectId/dev-keys')
            ->desc('Create dev key')
            ->groups(['api', 'projects'])
            ->label('scope', 'devKeys.write')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'devKeys',
                name: 'createDevKey',
                description: <<<EOT
                Create a new project dev key. Dev keys are project specific and allow you to bypass rate limits and get better error logging during development. Strictly meant for development purposes only.
                EOT,
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
            ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
            ->param('expire', null, new DatetimeValidator(), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format.', false)
            ->inject('user')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $name, ?string $expire, Document $user, Response $response, Database $dbForPlatform)
    {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $devKeyId = ID::unique();
        $key = new Document([
            '$id' => $devKeyId,
            '$permissions' => [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ],
            'projectInternalId' => $project->getSequence(),
            'projectId' => $project->getId(),
            'name' => $name,
            'expire' => $expire,
            'sdks' => [],
            'accessedAt' => null,
            'secret' => \bin2hex(\random_bytes(128)),
        ]);

        $key = $dbForPlatform->createDocument('devKeys', $key);

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($key, Response::MODEL_DEV_KEY);
    }
}
