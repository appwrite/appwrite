<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos;

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

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateVideo';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/videos/:videoId')
            ->desc('Update video')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.write')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('event', 'videos.[videoId].update')
            ->label('audits.event', 'video.update')
            ->label('audits.resource', 'video/{request.videoId}')
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'videos',
                name: 'updateVideo',
                description: '/docs/references/videos/update-video.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO,
                    )
                ]
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID holding the new source file.')
            ->param('fileId', '', new UID(), 'New source file unique ID.')
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
        string $bucketId,
        string $fileId,
        Response $response,
        Database $dbForProject,
        Document $project,
        User $user,
        Authorization $authorization,
        Event $queueForEvents,
        VideoPublisher $publisherForVideos
    ): void {
        $video = $this->getReadableVideo($dbForProject, $authorization, $user, $videoId);
        $file = $this->assertFileAccess($dbForProject, $authorization, $user, $bucketId, $fileId);

        $mimeType = $file->getAttribute('mimeType', '');
        $isSupported = \in_array($mimeType, self::SOURCE_MIME_TYPES, true);

        foreach (self::SOURCE_MIME_PREFIXES as $prefix) {
            if (\str_starts_with($mimeType, $prefix)) {
                $isSupported = true;
                break;
            }
        }

        if (!$isSupported) {
            throw new Exception(Exception::VIDEO_NOT_VALID);
        }

        // Everything derived from the previous source is now stale, so clear it and
        // let the worker re-probe. These columns all exist on the schema — the
        // pre-merge code wrote previewId/videoCodec/audioCodec without them.
        $video
            ->setAttribute('bucketId', $file->getAttribute('bucketId', $bucketId))
            ->setAttribute('bucketInternalId', $file->getAttribute('bucketInternalId', ''))
            ->setAttribute('fileId', $file->getId())
            ->setAttribute('fileInternalId', $file->getSequence())
            ->setAttribute('size', $file->getAttribute('sizeOriginal', 0))
            ->setAttribute('search', \implode(' ', [$file->getId(), $file->getAttribute('name', '')]))
            ->setAttribute('previewId', null)
            ->setAttribute('previewInternalId', null)
            ->setAttribute('format', null)
            ->setAttribute('duration', null)
            ->setAttribute('width', null)
            ->setAttribute('height', null)
            ->setAttribute('aspectRatio', null)
            ->setAttribute('videoCodec', null)
            ->setAttribute('videoFormat', null)
            ->setAttribute('videoFormatProfile', null)
            ->setAttribute('videoBitRate', null)
            ->setAttribute('videoFrameRate', null)
            ->setAttribute('videoFrameRateMode', null)
            ->setAttribute('audioCodec', null)
            ->setAttribute('audioFormat', null)
            ->setAttribute('audioBitRate', null)
            ->setAttribute('audioSampleRate', null);

        $video = $authorization->skip(fn () => $dbForProject->updateDocument('videos', $video->getId(), $video));

        $publisherForVideos->enqueue(new VideoMessage(
            project: $project,
            action: VideoAction::Timeline,
            video: $video,
        ));

        $queueForEvents->setParam('videoId', $video->getId());

        $response->dynamic($video, Response::MODEL_VIDEO);
    }
}
