<?php

namespace Appwrite\Platform\Modules\Avatars\Http\Photo;

use Appwrite\Abuse\Limit;
use Appwrite\AvatarPhotos\Providers\Fallback;
use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Event\Event;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
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
            ->label('scope', ['avatars.write', 'users.write'])
            ->label('event', 'users.[userId].update.avatar')
            ->label('audits.event', 'user.update')
            ->label('audits.userId', '{user.$id}')
            ->label('usage.resource', 'user/{user.$id}')
            ->label('abuse', [Limit::attempts(['ip', 'method', 'url', 'userId'], APP_LIMIT_WRITE_RATE_DEFAULT, seconds: APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)])
            ->label('sdk', new Method(
                namespace: 'avatars',
                group: null,
                name: 'deletePhoto',
                description: <<<'EOT'
                Delete the profile photo of the currently authenticated user, or of the user passed as `userId`, and store the built-in static placeholder in its place. The placeholder is the user's photo from then on, so it takes priority over every other photo source — OAuth2 identity photos, Gravatar, Libravatar, and initials — until a new photo is uploaded with avatars.updatePhoto.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::JSON
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'User ID whose photo to change. Needs the `users.write` scope. Defaults to the currently authenticated user.', true, ['dbForProject'])
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('deviceForFiles')
            ->inject('auditContext')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        Request $request,
        Response $response,
        Database $dbForProject,
        User $user,
        Event $queueForEvents,
        Device $deviceForFiles,
        AuditContext $auditContext,
    ): void {
        $user = $this->getTarget($userId, $user, $request, $dbForProject);

        // The placeholder becomes the user's own photo, so it shadows every other source — identity photos, Gravatar, Libravatar, initials — the way an upload does
        $photo = (new Fallback())->render(self::RESOLUTION, self::RESOLUTION);

        $this->replacePhoto($user, new Stream($photo), \strlen($photo), 'image/png', $dbForProject, $deviceForFiles);

        $queueForEvents->setParam('userId', $user->getId());

        // A 204 has no body for an audits.resource label to read, and the user may not be the caller
        $auditContext->resource = 'user/' . $user->getId();

        $response->noContent();
    }
}
