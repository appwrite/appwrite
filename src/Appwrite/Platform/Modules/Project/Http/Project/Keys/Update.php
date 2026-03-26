<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Keys;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
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
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectKey';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/project/keys/:keyId')
            ->httpAlias('/v1/projects/:projectId/keys/:keyId')
            ->desc('Update project key')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'keys.[keyId].update')
            ->label('audits.event', 'project.key.update')
            ->label('audits.resource', 'project.key/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'keys',
                name: 'updateKey',
                description: <<<EOT
                Update a key by its unique ID. Use this endpoint to update the name, scopes, or expiration time of an API key.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_KEY,
                    )
                ]
            ))
            ->param('keyId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getAdapter()->getMaxUIDLength()), 'Key ID.', false, ['dbForPlatform'])
            ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
            ->param('scopes', null, new Nullable(new ArrayList(new WhiteList(array_keys(Config::getParam('projectScopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed.')
            ->param('expire', null, new Nullable(new Datetime()), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Use null for unlimited expiration.', true)
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $keyId,
        string $name,
        array $scopes,
        ?string $expire,
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
    ) {
        $key = $authorization->skip(fn () => $dbForPlatform->getDocument('keys', $keyId));

        if ($key->isEmpty() || $key->getAttribute('resourceType', '') !== 'projects' || $key->getAttribute('resourceInternalId', '') !== $project->getSequence()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        // TODO: If authorized as API key, verify scopes and expiry is OK

        $updates = new Document([
            'name' => $name,
            'scopes' => $scopes,
            'expire' => $expire,
        ]);

        try {
            $key = $authorization->skip(fn () => $dbForPlatform->updateDocument('keys', $key->getId(), $updates));
        } catch (Duplicate $th) {
            throw new Exception(Exception::KEY_ALREADY_EXISTS);
        }

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents->setParam('keyId', $key->getId());

        // TODO: If authorized as api key, hide secret of key

        $response->dynamic($key, Response::MODEL_KEY);
    }
}
