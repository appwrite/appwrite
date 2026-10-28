<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Keys\Ephemeral;

use Ahc\Jwt\JWT;
use Appwrite\Auth\Key;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;

/**
 * TODO: Remove once the Console, CLI and SDKs use the Organization API
 * (/v1/organization/projects/:projectId/keys) instead of this project-scoped route.
 */
class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createEphemeralProjectKey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/project/keys/ephemeral')
            ->httpAlias('/v1/projects/:projectId/jwts')
            ->desc('Create ephemeral project key')
            ->groups(['api', 'project'])
            ->label('scope', 'keys.write')
            ->label('event', 'keys.[keyId].create')
            ->label('audits.event', 'project.key.create')
            ->label('audits.resource', 'project.key/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'keys',
                name: 'createEphemeralKey',
                description: <<<EOT
                Create a new ephemeral API key. It's recommended to have multiple API keys with strict scopes for separate functions within your project.

                You can also create a standard API key if you need a longer-lived key instead.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_EPHEMERAL_KEY,
                    )
                ],
            ))
            ->param('scopes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('projectScopes')), true), APP_LIMIT_ARRAY_SCOPES_SIZE), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_SCOPES_SIZE . ' scopes are allowed.', optional: false, example: '["users.read"]', enum: new Enum(name: 'ProjectKeyScopes'))
            ->param('duration', null, new Range(1, 3600), 'Time in seconds before ephemeral key expires. Maximum duration is 3600 seconds.', optional: false, example: 600)
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('apiKey')
            ->inject('request')
            ->callback($this->action(...));
    }

    public function action(
        array $scopes,
        int $duration,
        Response $response,
        QueueEvent $queueForEvents,
        Document $project,
        ?Key $apiKey,
        Request $request,
    ) {
        $now = new \DateTime();
        $expire = $now->add(new \DateInterval('PT' . $duration . 'S'))->format('Y-m-d\TH:i:s.u\Z');

        // A JWT stands in for its session for a few minutes; a key it left behind would outlive it
        if ($request->getHeaderLine('x-appwrite-jwt', '') !== '') {
            throw new Exception(Exception::KEY_CREATION_DENIED);
        }

        // A key hands out no more than it holds: no broader scopes and no later expiry
        if ($apiKey !== null && !$apiKey->mayCreate(API_KEY_EPHEMERAL, $scopes, $expire)) {
            throw new Exception(Exception::KEY_CREATION_DENIED);
        }

        $keyId = Id::unique();

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $duration, 0);

        $secret = $jwt->encode([
            Key::PURPOSE_CLAIM => Key::PURPOSE,
            'projectId' => $project->getId(),
            'projectSequence' => $project->getSequence(),
            'scopes' => $scopes
        ]);

        $key = new Document([
            '$id' => $keyId,
            '$createdAt' => DatabaseDateTime::now(),
            '$updatedAt' => DatabaseDateTime::now(),
            'name' => '',
            'scopes' => $scopes,
            'expire' => $expire,
            'sdks' => [],
            'accessedAt' => null,
            'secret' => API_KEY_EPHEMERAL . '_' . $secret,
        ]);

        $queueForEvents->setParam('keyId', $key->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($key, Response::MODEL_EPHEMERAL_KEY);
    }
}
