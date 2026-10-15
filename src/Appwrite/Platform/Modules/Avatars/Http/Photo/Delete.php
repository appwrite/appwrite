<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Photo;

use Appwrite\AvatarPhotos\Providers\Fallback;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device;

class Delete extends Base
{
    /**
     * Square edge of the stored placeholder in pixels — large enough that
     * the usual avatar sizes only ever scale it down.
     */
    private const RESOLUTION = 1024;

    public static function getName(): string
    {
        return 'deletePhoto';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/avatars/photo')
            ->desc('Delete photo')
            ->groups(['api', 'avatars'])
            ->label('scope', 'avatars.write')
            ->label('event', 'users.[userId].update.avatar')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('audits.userId', '{user.$id}')
            ->label('usage.resource', 'user/{user.$id}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'deletePhoto',
                description: <<<'EOT'
                Delete the profile photo of the currently authenticated user and store the built-in static placeholder in its place. The placeholder is the user's photo from then on, so it takes priority over every other photo source — OAuth2 identity photos, Gravatar, Libravatar, and initials — until a new photo is uploaded with avatars.updatePhoto.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::NONE
            ))
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('deviceForFiles')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Database $dbForProject,
        User $user,
        Event $queueForEvents,
        Device $deviceForFiles,
    ): void {
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        if (!\extension_loaded('imagick')) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Imagick extension is missing');
        }

        // The placeholder becomes the user's own photo, so it shadows every other source — identity photos, Gravatar, Libravatar, initials — the way an upload does
        $photo = (new Fallback())->render(self::RESOLUTION, self::RESOLUTION);

        $this->replacePhoto($user, new Stream($photo), \strlen($photo), 'image/png', $dbForProject, $deviceForFiles);

        $queueForEvents->setParam('userId', $user->getId());

        $response->noContent();
    }
}
