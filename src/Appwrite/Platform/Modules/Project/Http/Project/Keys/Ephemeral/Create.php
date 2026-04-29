<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Keys\Ephemeral;

use Ahc\Jwt\JWT;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;

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
            ->param('scopes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('projectScopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed.', optional: false)
            ->param('duration', 900, new Range(1, 3600), 'Time in seconds before ephemeral key expires. Default duration is 900 seconds, and maximum is 3600 seconds.', true)
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        array $scopes,
        int $duration,
        Response $response,
        QueueEvent $queueForEvents,
        Document $project,
    ) {
        $keyId = ID::unique();

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $duration, 0);

        $secret = $jwt->encode([
            'projectId' => $project->getId(),
            'scopes' => $scopes
        ]);

        $now = new \DateTime();
        $expire = $now->add(new \DateInterval('PT' . $duration . 'S'))->format('Y-m-d\TH:i:s.u\Z');

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
