<?php

namespace Appwrite\Platform\Modules\Teams\Http\Teams;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getTeam';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/teams/:teamId')
            ->desc('Get team')
            ->groups(['api', 'teams'])
            ->label('scope', 'teams.read')
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'teams',
                name: 'get',
                description: '/docs/references/teams/get-team.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TEAM,
                    )
                ]
            ))
            ->param('teamId', '', new UID(), 'Team ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $teamId, Response $response, Database $dbForProject)
    {
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $response->dynamic($team, Response::MODEL_TEAM);
    }
}
