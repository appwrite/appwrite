<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Timeline;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;

class Get extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'getTimeline';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/timeline')
            ->desc('Get timeline')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'videos',
                name: 'getTimeline',
                description: '/docs/references/videos/get-timeline.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::TEXT,
                type: MethodType::LOCATION,
                locationAuth: ['Project', 'ImpersonateUserId'],
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->inject('deviceForVideos')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization,
        Device $deviceForVideos
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);

        // Scoped to this video's sprites. The pre-merge check queried every sprite
        // in the project, so any video with a timeline made all of them look ready.
        $sprites = $authorization->skip(fn () => $dbForProject->find('videos_previews', [
            Query::equal('videoInternalId', [$video->getSequence()]),
            Query::equal('type', ['sprite']),
            Query::limit(1),
        ]));

        if (empty($sprites)) {
            throw new Exception(Exception::VIDEO_TIMELINE_NOT_FOUND);
        }

        $path = $deviceForVideos->getPath($video->getId() . '/timeline') . '/timeline.vtt';

        if (!$deviceForVideos->exists($path)) {
            throw new Exception(Exception::VIDEO_TIMELINE_NOT_FOUND);
        }

        $response
            ->setContentType('text/vtt')
            ->addHeader('Cache-Control', 'private, max-age=3888000') // 45 days
            ->send((string) $deviceForVideos->read($path));
    }
}
