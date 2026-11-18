<?php

namespace Appwrite\Platform\Modules\Teams\Http\Teams\Photo;

use Appwrite\Abuse\Limit;
use Appwrite\AvatarPhotos\Providers\Fallback;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device;

class Delete extends Action
{
    use HTTP;


    public static function getName(): string
    {
        return 'deleteTeamPhoto';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/teams/:teamId/photo')
            ->desc('Delete photo')
            ->groups(['api', 'teams'])
            ->label('event', 'teams.[teamId].update')
            ->label('scope', 'teams.write')
            ->label('audits.event', 'team.update')
            ->label('audits.resource', 'team/{request.teamId}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->param('teamId', '', new UID(), 'Team ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('deviceForFiles')
            ->callback($this->action(...));
    }

    public function action(
        string $teamId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Device $deviceForFiles,
    ): void {
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }


        $response->noContent();
    }
}
