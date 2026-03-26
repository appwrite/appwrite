<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Keys;

use Appwrite\Auth\Key;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
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
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
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
            ->setHttpPath('/v1/project/keys')
            ->httpAlias('/v1/projects/:projectId/keys')
            ->desc('Create project key')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'keys.[keyId].create')
            ->label('audits.event', 'project.key.create')
            ->label('audits.resource', 'project.key/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'keys',
                name: 'createKey',
                description: <<<EOT
                Create a new API key. It's recommended to have multiple API keys with strict scopes for separate functions within your project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_KEY,
                    )
                ],
            ))
            ->param('keyId', '', fn (Database $dbForPlatform) => new CustomId(false, $dbForPlatform->getAdapter()->getMaxUIDLength()), 'Key ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForPlatform'])
            ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
            ->param('scopes', null, new Nullable(new ArrayList(new WhiteList(array_keys(Config::getParam('projectScopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed.')
            ->param('expire', null, new Nullable(new Datetime()), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Use null for unlimited expiration.', true)
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('apiKey')
            ->callback($this->action(...));
    }

    /**
     * @param array<string>|null $scopes
     */
    public function action(
        string $keyId,
        string $name,
        ?array $scopes,
        ?string $expire,
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        ?Key $apiKey,
    ) {
        $keyId = ($keyId == 'unique()') ? ID::unique() : $keyId;

        $isProjectApiKey = $apiKey !== null && !empty($apiKey->getProjectId());

        if ($isProjectApiKey) {
            if (!empty(\array_diff($scopes ?? [], $apiKey->getScopes()))) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'New API key cannot exceed scopes of currently authenticated API key.');
            }

            if (\is_null($expire) && !\is_null($apiKey->getExpire())) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'New API key must have expiry set, because currently authenticated API key has an expiry.');
            }

            if (!\is_null($expire) && $expire > $apiKey->getExpire()) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'New API key expiry must be sooner than currently authenticated API key expiry.');
            }
        }

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
