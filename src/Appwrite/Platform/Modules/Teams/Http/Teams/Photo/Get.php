<?php

namespace Appwrite\Platform\Modules\Teams\Http\Teams\Photo;

use Appwrite\AvatarPhotos\Providers\Custom;
use Appwrite\AvatarPhotos\Providers\Fallback;
use Appwrite\AvatarPhotos\Providers\Initials;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Image\Canvas;
use Utopia\Image\Format;
use Utopia\Image\Transform;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getTeamPhoto';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/teams/:teamId/photo')
            ->desc('Get photo')
            ->groups(['api', 'teams'])
            ->label('scope', 'teams.read')
            ->param('teamId', '', new UID(), 'Team ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('deviceForFiles')
            ->callback($this->action(...));
    }

    public function action(
        string $teamId,
        int $width,
        int $height,
        int $quality,
        string $output,
        Response $response,
        Database $dbForProject,
        Device $deviceForFiles,
    ): void {
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

    }

}
