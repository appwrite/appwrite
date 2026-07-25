<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Profiles;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deleteProfile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/videos/profiles/:profileId')
            ->desc('Delete video profile')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('audits.event', 'profile.delete')
            ->label('audits.resource', 'videoProfile/{request.profileId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'profiles',
                name: 'deleteProfile',
                description: '/docs/references/videos/delete-profile.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('profileId', '', new UID(), 'Video profile unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $profileId,
        Response $response,
        Database $dbForProject,
        Authorization $authorization,
        Event $queueForEvents
    ): void {
        $profile = $authorization->skip(fn () => $dbForProject->getDocument('videos_profiles', $profileId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        // Renditions already encoded against this profile keep their own copies of
        // the dimensions and bitrates, so they stay playable.
        $deleted = $authorization->skip(fn () => $dbForProject->deleteDocument('videos_profiles', $profile->getId()));

        if (!$deleted) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove video profile from DB');
        }

        $queueForEvents
            ->setParam('profileId', $profile->getId())
            ->setPayload($response->output($profile, Response::MODEL_VIDEO_PROFILE));

        $response->noContent();
    }
}
