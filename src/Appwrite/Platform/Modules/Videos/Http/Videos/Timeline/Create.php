<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Timeline;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Video as VideoMessage;
use Appwrite\Event\Message\VideoAction;
use Appwrite\Event\Publisher\Video as VideoPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createTimeline';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/videos/:videoId/timeline')
            ->desc('Create video timeline')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].timeline.create')
            ->label('audits.event', 'timeline.create')
            ->label('audits.resource', 'video/{request.videoId}')
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'videos',
                name: 'createTimeline',
                description: '/docs/references/videos/create-timeline.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_VIDEO,
                    )
                ]
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->inject('publisherForVideos')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization,
        Event $queueForEvents,
        VideoPublisher $publisherForVideos
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);
        $this->assertSourceReady($video);

        $width = (int) $video->getAttribute('width', 0);
        $height = (int) $video->getAttribute('height', 0);
        if ($width <= 0 || $height <= 0) {
            throw new Exception(Exception::VIDEO_TRACK_NOT_FOUND);
        }

        $publisherForVideos->enqueue(new VideoMessage(
            project: $project,
            action: VideoAction::Timeline,
            video: $video,
        ));

        $queueForEvents->setParam('videoId', $video->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($video, Response::MODEL_VIDEO);
    }
}
