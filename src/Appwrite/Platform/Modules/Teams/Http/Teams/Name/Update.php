<?php

namespace Appwrite\Platform\Modules\Teams\Http\Teams\Name;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateTeamName';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/teams/:teamId')
            ->desc('Update name')
            ->groups(['api', 'teams'])
            ->label('event', 'teams.[teamId].update')
            ->label('scope', 'teams.write')
            ->label('audits.event', 'team.update')
            ->label('audits.resource', 'team/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'teams',
                name: 'updateName',
                description: '/docs/references/teams/update-team-name.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TEAM,
                    )
                ]
            ))
            ->param('teamId', '', new UID(), 'Team ID.')
            ->param('name', null, new Text(128), 'New team name. Max length: 128 chars.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $teamId, string $name, Response $response, Database $dbForProject, Event $queueForEvents)
    {
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $team
            ->setAttribute('name', $name)
            ->setAttribute('search', implode(' ', [$teamId, $name]));

        $team = $dbForProject->updateDocument('teams', $team->getId(), $team);

        $queueForEvents->setParam('teamId', $team->getId());

        $response->dynamic($team, Response::MODEL_TEAM);
    }
}