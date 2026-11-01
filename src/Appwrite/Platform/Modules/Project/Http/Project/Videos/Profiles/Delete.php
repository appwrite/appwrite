<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Videos\Profiles;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'deleteVideoProfile';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/project/videos/profiles/:profileId')
            ->httpAlias('/v1/projects/:projectId/videos/profiles/:profileId')
            ->desc('Delete video profile')
            ->groups(['api', 'project'])
            ->label('scope', 'project.profiles.write')
            ->label('event', 'projects.[projectId].videos.profiles.[profileId].delete')
            ->label('audits.event', 'project.video-profile.delete')
            ->label('audits.resource', 'project.video-profile/{request.profileId}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'profiles',
                name: 'deleteVideoProfile',
                description: '/docs/references/project/delete-video-profile.md',
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
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $profileId,
        Response $response,
        Database $dbForProject,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents
    ): void {
        $profile = $authorization->skip(fn () => $dbForProject->getDocument('videosProfiles', $profileId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        // Renditions already encoded against this profile keep their own copies of
        // the dimensions and bitrates, so they stay playable.
        $deleted = $authorization->skip(fn () => $dbForProject->deleteDocument('videosProfiles', $profile->getId()));

        if (!$deleted) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove video profile from DB');
        }

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('profileId', $profile->getId())
            ->setPayload($response->output($profile, Response::MODEL_VIDEO_PROFILE));

        $response->noContent();
    }
}
