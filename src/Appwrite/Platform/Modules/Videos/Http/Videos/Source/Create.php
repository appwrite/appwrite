<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Source;

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
        return 'createSource';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/videos/:videoId/source')
            ->desc('Create video source')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].source.create')
            ->label('audits.event', 'source.create')
            ->label('audits.resource', 'video/{request.videoId}')
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'videos',
                name: 'createSource',
                description: '/docs/references/videos/create-source.md',
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
        $status = (string) $video->getAttribute('status', '');

        // A live or in-flight working copy must not be fetched twice — reject
        // explicitly rather than no-op so the caller knows the request did
        // nothing. `pending`, `removed` and `error` fall through to enqueue.
        if ($status === self::SOURCE_DOWNLOADING) {
            throw new Exception(Exception::VIDEO_SOURCE_IN_PROGRESS);
        }

        if ($status === self::SOURCE_READY) {
            if (\is_file(self::tmpSourcePath($project->getId(), $video->getId()))) {
                throw new Exception(Exception::VIDEO_SOURCE_ALREADY_EXISTS);
            }

            // Disk is the truth: the row claims a live working copy but the file
            // is gone (crash, manual cleanup) — videos-tmp is shared with this
            // container, so correct the row and re-download in the same call.
            $video = $authorization->skip(fn () => $dbForProject->updateDocument('videos', $video->getId(), new Document([
                'status' => self::SOURCE_REMOVED,
            ])));
        }

        $publisherForVideos->enqueue(new VideoMessage(
            project: $project,
            action: VideoAction::Download,
            video: $video,
        ));

        $queueForEvents->setParam('videoId', $video->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($video, Response::MODEL_VIDEO);
    }
}
