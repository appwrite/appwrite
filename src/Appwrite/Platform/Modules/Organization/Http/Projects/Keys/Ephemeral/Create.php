<?php

namespace Appwrite\Platform\Modules\Organization\Http\Projects\Keys\Ephemeral;

use Ahc\Jwt\JWT;
use Appwrite\Auth\Key;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Platform\Modules\Organization\Http\Projects\Keys\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;

class Create extends Action
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
            ->setHttpPath('/v1/organization/projects/:projectId/keys/ephemeral')
            ->httpAlias('/v1/project/keys/ephemeral')
            ->httpAlias('/v1/projects/:projectId/jwts')
            ->desc('Create ephemeral project key')
            ->groups(['api', 'organization'])
            ->label('scope', 'keys.write')
            ->label('event', 'keys.[keyId].create')
            ->label('audits.event', 'project.key.create')
            ->label('audits.resource', 'project.key/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'keys',
                name: 'createEphemeralProjectKey',
                description: <<<EOT
                Create a new ephemeral API key for a project in your organization. It's recommended to have multiple API keys with strict scopes for separate functions within your project.

                You can also create a standard API key if you need a longer-lived key instead.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_EPHEMERAL_KEY,
                    )
                ],
            ))
            ->param('projectId', fn (Document $project) => $project->getId(), new UID(), 'Project unique ID.', true, ['project'])
            ->param('scopes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('projectScopes')), true), APP_LIMIT_ARRAY_SCOPES_SIZE), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_SCOPES_SIZE . ' scopes are allowed.', optional: false, enum: new Enum(name: 'ProjectKeyScopes'))
            ->param('duration', null, new Range(1, 3600), 'Time in seconds before ephemeral key expires. Maximum duration is 3600 seconds.', optional: false, example: 600)
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('team')
            ->inject('apiKey')
            ->callback($this->action(...));
    }

    public function action(
        string $projectId,
        array $scopes,
        int $duration,
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForPlatform,
        Document $team,
        ?Key $apiKey,
    ) {
        $project = $this->getProject($projectId, $team, $dbForPlatform, $apiKey);

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
