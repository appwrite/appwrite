<?php

namespace Appwrite\Platform\Modules\Organization\Http\Projects\Keys;

use Appwrite\Auth\Key;
use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createProjectKey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/organization/projects/:projectId/keys')
            ->desc('Create project key')
            ->groups(['api', 'organization'])
            ->label('scope', 'keys.write')
            ->label('event', 'keys.[keyId].create')
            ->label('audits.event', 'project.key.create')
            ->label('audits.resource', 'project.key/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'keys',
                name: 'createProjectKey',
                description: <<<EOT
                Create a new API key for a project in your organization. It's recommended to have multiple API keys with strict scopes for separate functions within your project.

                You can also create an ephemeral API key if you need a short-lived key instead.
                EOT,
                auth: [AuthType::ADMIN, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_KEY,
                    )
                ],
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('keyId', '', fn (Database $dbForPlatform) => new CustomId(false, $dbForPlatform->getAdapter()->getMaxUIDLength()), 'Key ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForPlatform'])
            ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
            ->param('scopes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('projectScopes')), true), APP_LIMIT_ARRAY_SCOPES_SIZE), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_SCOPES_SIZE . ' scopes are allowed.', optional: false, enum: new Enum(name: 'ProjectKeyScopes'))
            ->param('expire', null, new Nullable(new Datetime()), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Use null for unlimited expiration.', true)
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('team')
            ->inject('authorization')
            ->inject('apiKey')
            ->inject('auditContext')
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
        AuditContext $auditContext,
    ) {
        // Dynamic supported for backwards compatibility
        if ($apiKey !== null && \in_array($apiKey->getType(), [API_KEY_STANDARD, API_KEY_EPHEMERAL, 'dynamic'], true)) {
            throw new Exception(Exception::KEY_CREATION_DENIED);
        }

        $project = $this->getProject($projectId, $team, $dbForPlatform, $apiKey);

        // The request may run through the console project; events and audits belong to the resolved project
        $queueForEvents->setProject($project);
        $auditContext->project = $project;
        $keyId = ($keyId == 'unique()') ? ID::unique() : $keyId;

        $key = new Document([
            '$id' => $keyId,
            '$permissions' => [],
            'resourceInternalId' => $project->getSequence(),
            'resourceId' => $project->getId(),
            'resourceType' => 'projects',
            'name' => $name,
            'scopes' => $scopes,
            'expire' => $expire,
            'sdks' => [],
            'accessedAt' => null,
            'secret' => API_KEY_STANDARD . '_' . \bin2hex(\random_bytes(128)),
        ]);

        try {
            $key = $authorization->skip(fn () => $dbForPlatform->createDocument('keys', $key));
        } catch (DuplicateException) {
            throw new Exception(Exception::KEY_ALREADY_EXISTS);
        }

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('keyId', $key->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($key, Response::MODEL_KEY);
    }
}
