<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Users\Photo;

use Appwrite\Abuse\Limit;
use Appwrite\AvatarPhotos\Providers\Fallback;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Avatars\Http\Photo\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action as UtopiaAction;
use Utopia\Psr7\Stream;
use Utopia\Storage\Device;

class Delete extends Base
{
    private const RESOLUTION = 1024;

    public static function getName(): string
    {
        return 'deleteUserPhoto';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(UtopiaAction::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/avatars/users/:userId/photo')
            ->desc('Delete photo')
            ->groups(['api', 'avatars', 'users'])
            // User photos belong to Users too, so the project's Users switch still covers them
            ->label('services', ['users'])
            ->label('event', 'users.[userId].update.avatar')
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{request.userId}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'deleteUserPhoto',
                description: <<<'EOT'
                Delete the profile photo of a user and store the built-in static placeholder in its place. The placeholder is the user's photo from then on, so it takes priority over every other photo source — OAuth2 identity photos, Gravatar, Libravatar, and initials — until a new photo is uploaded with avatars.updateUserPhoto.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::NONE
            ))
            ->param('userId', '', new UID(), 'User ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('deviceForFiles')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Device $deviceForFiles,
    ): void {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        // The placeholder becomes the user's own photo, so it shadows every other source the way an upload does
        $photo = (new Fallback())->render(self::RESOLUTION, self::RESOLUTION);
        $this->replacePhoto($user, new Stream($photo), \strlen($photo), 'image/png', $dbForProject, $deviceForFiles);

        $queueForEvents->setParam('userId', $userId);

        $response->noContent();
    }
}
