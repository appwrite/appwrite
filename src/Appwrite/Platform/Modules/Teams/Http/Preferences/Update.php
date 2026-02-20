<?php

namespace Appwrite\Platform\Modules\Teams\Http\Preferences;

use Appwrite\Event\Event;
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
use Utopia\Validator\Assoc;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateTeamPreferences';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/teams/:teamId/prefs')
            ->groups(['api', 'teams'])
            ->label('event', 'teams.[teamId].update.prefs')
            ->label('scope', 'teams.write')
            ->label('audits.event', 'team.update')
            ->label('audits.resource', 'team/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'teams',
                name: 'updatePrefs',
                description: '/docs/references/teams/update-team-prefs.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PREFERENCES,
                    )
                ]
            ))
            ->param('teamId', '', new UID(), 'Team ID.')
            ->param('prefs', '', new Assoc(), 'Prefs key-value JSON object.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $teamId, array $prefs, Response $response, Database $dbForProject, Event $queueForEvents)
    {
        try {
            $prefs = new Document($prefs);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $team = $dbForProject->updateDocument('teams', $team->getId(), new Document([
            'prefs' => $prefs->getArrayCopy()
        ]));

        $queueForEvents->setParam('teamId', $team->getId());

        $response->dynamic($prefs, Response::MODEL_PREFERENCES);
    }
}
