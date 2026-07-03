<?php

namespace Appwrite\Platform\Modules\Teams\Http\Preferences;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getTeamPreferences';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/teams/:teamId/prefs')
            ->desc('Get team preferences')
            ->groups(['api', 'teams'])
            ->label('scope', 'teams.read')
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'teams',
                name: 'getPrefs',
                description: '/docs/references/teams/get-team-prefs.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PREFERENCES,
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

        $prefs = $team->getAttribute('prefs', []);

        try {
            $prefs = new Document($prefs);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        $response->dynamic($prefs, Response::MODEL_PREFERENCES);
    }
}
