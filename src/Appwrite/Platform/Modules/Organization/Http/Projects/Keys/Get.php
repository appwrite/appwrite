<?php

namespace Appwrite\Platform\Modules\Organization\Http\Projects\Keys;

use Appwrite\Auth\Key;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getProjectKey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/organization/projects/:projectId/keys/:keyId')
            ->desc('Get project key')
            ->groups(['api', 'organization'])
            ->label('scope', ['organization.projects.keys.read', 'keys.read'])
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'keys',
                name: 'getProjectKey',
                description: <<<EOT
                Get a project key by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_KEY,
                    )
                ]
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('keyId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Key ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('team')
            ->inject('authorization')
            ->inject('apiKey')
            ->callback($this->action(...));
    }

    public function action(
        string $projectId,
        string $keyId,
        Response $response,
        Database $dbForPlatform,
        Document $team,
        Authorization $authorization,
        ?Key $apiKey,
    ) {
        $project = $this->getProject($projectId, $team, $dbForPlatform, $apiKey);

        $key = $authorization->skip(fn () => $dbForPlatform->getDocument('keys', $keyId));

        if ($key->isEmpty() || $key->getAttribute('resourceType', '') !== 'projects' || $key->getAttribute('resourceInternalId', '') !== $project->getSequence()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $response->dynamic($key, Response::MODEL_KEY);
    }
}
