<?php

namespace Appwrite\Platform\Modules\Organization\Http\Projects\Keys;

use Appwrite\Auth\Key;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectKey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/organization/projects/:projectId/keys/:keyId')
            ->httpAlias('/v1/project/keys/:keyId')
            ->httpAlias('/v1/projects/:projectId/keys/:keyId')
            ->desc('Update project key')
            ->groups(['api', 'organization'])
            ->label('scope', 'keys.write')
            ->label('event', 'keys.[keyId].update')
            ->label('audits.event', 'project.key.update')
            ->label('audits.resource', 'project.key/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'keys',
                name: 'updateProjectKey',
                description: <<<EOT
                Update a project key by its unique ID. Use this endpoint to update the name, scopes, or expiration time of an API key.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_KEY,
                    )
                ]
            ))
            ->param('projectId', fn (Document $project) => $project->getId(), new UID(), 'Project unique ID.', true, ['project'])
            ->param('keyId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Key ID.', false, ['dbForPlatform'])
            ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
            ->param('scopes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('projectScopes')), true), APP_LIMIT_ARRAY_SCOPES_SIZE), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_SCOPES_SIZE . ' scopes are allowed.', optional: false, enum: new Enum(name: 'ProjectKeyScopes'))
            ->param('expire', null, new Nullable(new Datetime()), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Use null for unlimited expiration.', true)
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('team')
            ->inject('authorization')
            ->inject('apiKey')
            ->callback($this->action(...));
    }

    public function action(
        string $projectId,
        string $keyId,
        string $name,
        array $scopes,
        ?string $expire,
        Response $response,
        QueueEvent $queueForEvents,
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

        $updates = new Document([
            'name' => $name,
            'scopes' => $scopes,
            'expire' => $expire,
        ]);

        try {
            $key = $authorization->skip(fn () => $dbForPlatform->updateDocument('keys', $key->getId(), $updates));
        } catch (Duplicate) {
            throw new Exception(Exception::KEY_ALREADY_EXISTS);
        }

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('keyId', $key->getId());

        $response->dynamic($key, Response::MODEL_KEY);
    }
}
