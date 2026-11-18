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

    private const RESOLUTION = 1024;

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
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'teams',
                name: 'deletePhoto',
                description: <<<'EOT'
                Delete the photo of a team and store the built-in static placeholder in its place. The placeholder is the team's photo from then on, so it takes priority over the initials of the team name until a new photo is uploaded with teams.updatePhoto.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::NONE
            ))
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

        // The placeholder becomes the team's own photo, the way it does for a user
        $photo = (new Fallback())->render(self::RESOLUTION, self::RESOLUTION);

        $response->noContent();
    }
}
