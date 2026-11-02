<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Keys;

use Appwrite\Auth\Key;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

/**
 * TODO: Remove once the Console, CLI and SDKs use the Organization API
 * (/v1/organization/projects/:projectId/keys) instead of this project-scoped route.
 */
class Get extends Base
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
            ->setHttpPath('/v1/project/keys/:keyId')
            ->httpAlias('/v1/projects/:projectId/keys/:keyId')
            ->desc('Get project key')
            ->groups(['api', 'project'])
            ->label('scope', 'keys.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'keys',
                name: 'getKey',
                description: <<<EOT
                Get a key by its unique ID. 
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_KEY,
                    )
                ]
            ))
            ->param('keyId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Key ID.', false, ['dbForPlatform'])
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('request')
            ->inject('apiKey')
            ->callback($this->action(...));
    }

    public function action(
        string $keyId,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Request $request,
        ?Key $apiKey,
    ) {
        $key = $authorization->skip(fn () => $dbForPlatform->getDocument('keys', $keyId));

        if ($key->isEmpty() || $key->getAttribute('resourceType', '') !== 'projects' || $key->getAttribute('resourceInternalId', '') !== $project->getSequence()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        // Secrets are only for callers that could have created the key: a session with
        // keys.write, or a key whose grant covers it. A JWT never sees them.
        if (
            !\in_array('keys.write', $request->getScopes(), true)
            || $request->getHeaderLine('x-appwrite-jwt', '') !== ''
            || ($apiKey !== null && !$apiKey->mayCreate(API_KEY_STANDARD, $key->getAttribute('scopes', []), $key->getAttribute('expire')))
        ) {
            $key->setAttribute('secret', '');
        }

        $response->dynamic($key, Response::MODEL_KEY);
    }
}
