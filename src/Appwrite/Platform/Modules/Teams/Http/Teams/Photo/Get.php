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
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'teams',
                name: 'getPhoto',
                description: <<<'EOT'
                Returns the photo of a team: the uploaded photo (see teams.updatePhoto), else the initials of the team name, else a built-in static placeholder.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'Environment', 'ImpersonateUserId'],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::IMAGE
            ))
            ->param('teamId', '', new UID(), 'Team ID.')
            ->param('width', 256, new Range(0, 2000), 'Output image width in pixels. Pass an integer between 0 and 2000. Defaults to 256.', true)
            ->param('height', 256, new Range(0, 2000), 'Output image height in pixels. Pass an integer between 0 and 2000. Defaults to 256.', true)
            ->param('quality', 100, new Range(0, 100), 'Output image quality between 0 and 100. Defaults to 100.', true)
            ->param('output', 'png', new WhiteList(['png', 'jpg', 'webp'], true), 'Output image format. Defaults to \'png\'.', true)
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

        $profile = new Document([
            '$id' => $team->getId(),
            'name' => $team->getAttribute('name', ''),
            'photoId' => $team->getAttribute('photoId', ''),
        ]);

        $data = null;

        foreach ([new Custom($deviceForFiles, APP_STORAGE_TEAM_PHOTOS), new Initials(), new Fallback()] as $provider) {
            if ($provider->supports($profile)) {
                $data = $provider->get($profile, $width, $height, 'g');
            }

            if ($data !== null) {
                break;
            }
        }

        if ($data === null) {
            throw new Exception(Exception::AVATAR_NOT_FOUND);
        }

        $contentType = match ($output) {
            'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

    }

}
