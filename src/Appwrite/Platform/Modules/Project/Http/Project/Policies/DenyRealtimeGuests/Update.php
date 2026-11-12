<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\DenyRealtimeGuests;

use Appwrite\Event\Event;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateProjectDenyRealtimeGuestsPolicy';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/deny-realtime-guests')
            ->desc('Update deny realtime guests policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updateDenyRealtimeGuestsPolicy',
                description: <<<EOT
                Configures if guests, connections without a signed-in user, may subscribe to realtime channels. When enabled, a guest can still connect and sign in over the connection, but cannot subscribe until it does.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ]
            ))
            ->param('enabled', false, new Boolean(false), 'Set whether or not to stop guests from subscribing to realtime channels.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents
    ): void {
        $project = $dbForPlatform->withTransaction(function () use ($dbForPlatform, $authorization, $project, $enabled): Document {
            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $project->getId(), forUpdate: true));

            $auths = $project->getAttribute('auths', []);
            $auths['realtimeGuests'] = $enabled;

            return $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                'auths' => $auths
            ])));
        });

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'deny-realtime-guests');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
